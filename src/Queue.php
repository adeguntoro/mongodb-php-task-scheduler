<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Operation\Find;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Queue
{
    /**
     * Job status.
     */
    const STATUS_WAITING = 0;
    const STATUS_POSTPONED = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_DONE = 3;
    const STATUS_FAILED = 4;
    const STATUS_CANCELED = 5;

    /**
     * Scheduler.
     *
     * @param Scheduler
     */
    protected $scheduler;

    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * LoggerInterface.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Local queue.
     *
     * @var array
     */
    protected $queue = [];

    /**
     * Collection name.
     *
     * @var string
     */
    protected $collection_name = 'queue';

    /**
     * Container.
     *
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Current processing job.
     *
     * @var null|array
     */
    protected $current_job;

    /**
     * Init queue.
     *
     * @param Scheduler          $scheduler
     * @param Database           $db
     * @param LoggerInterface    $logger
     * @param ContainerInterface $container
     */
    public function __construct(Scheduler $scheduler, Database $db, LoggerInterface $logger, ?ContainerInterface $container = null)
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
        $this->collection_name = $scheduler->getCollection();
    }

    /**
     * Execute job queue as endless loop.
     */
    public function process()
    {
        $cursor = $this->getCursor();
        $this->catchSignal();

        while (true) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                    ]);

                    $this->createQueue();

                    return $this->process();
                }

                $this->retrieveNextJob($cursor);

                continue;
            }

            $job = $cursor->current();
            $this->retrieveNextJob($cursor);
            $this->queueJob($job);
        }
    }

    /**
     * Execute job queue.
     *
     * @return bool
     */
    public function processOnce(): bool
    {
        $cursor = $this->getCursor(false);

        while (true) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->debug('all jobs were processed', [
                        'category' => get_class($this),
                    ]);

                    return false;
                }

                return true;
            }

            $job = $cursor->current();
            $cursor->next();
            $this->queueJob($job);
        }
    }

    /**
     * Cleanup and exit.
     *
     * @param int $sig
     */
    public function cleanup(int $sig)
    {
        $this->handleSignal($sig);
        exit();
    }

    /**
     * Catch signals and cleanup.
     *
     * @return Queue
     */
    protected function catchSignal(): self
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);

        return $this;
    }

    /**
     * Cleanup and exit.
     *
     * @param int $sig
     *
     * @return ObjectId
     */
    protected function handleSignal(int $sig): ?ObjectId
    {
        if (null === $this->current_job) {
            $this->logger->debug('received signal ['.$sig.'], no job is currently processing, exit now', [
                'category' => get_class($this),
            ]);

            return null;
        }

        $this->logger->debug('received signal ['.$sig.'], reschedule current processing job ['.$this->current_job['_id'].']', [
            'category' => get_class($this),
        ]);

        $this->updateJob($this->current_job['_id'], self::STATUS_CANCELED);

        return $this->scheduler->addJob($this->current_job['class'], $this->current_job['data'], [
            Scheduler::OPTION_AT => $this->current_job['retry_interval'],
            Scheduler::OPTION_INTERVAL => $this->current_job['interval'],
            Scheduler::OPTION_RETRY => --$this->current_job['retry'],
            Scheduler::OPTION_RETRY_INTERVAL => $this->current_job['retry_interval'],
        ]);
    }

    /**
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return Queue
     */
    protected function createQueue(): self
    {
        $this->logger->info('create new queue ['.$this->collection_name.']', [
            'category' => get_class($this),
        ]);

        try {
            $this->db->createCollection(
                $this->collection_name,
                [
                    'capped' => true,
                    'size' => $this->scheduler->getQueueSize(),
                ]
            );

            $this->db->{$this->collection_name}->insertOne(['class' => 'dummy']);
        } catch (RuntimeException $e) {
            if (48 !== $e->getCode()) {
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return Queue
     */
    protected function convertQueue(): self
    {
        $this->logger->info('convert existing queue collection ['.$this->collection_name.'] into a capped collection', [
            'category' => get_class($this),
        ]);

        $this->db->command([
            'convertToCapped' => $this->collection_name,
            'size' => $this->scheduler->getQueueSize(),
        ]);

        $this->db->{$this->collection_name}->insertOne(['class' => 'dummy']);

        return $this;
    }

    /**
     * Retrieve next job.
     *
     * @param IteratorIterator $cursor
     */
    protected function retrieveNextJob(IteratorIterator $cursor)
    {
        try {
            $cursor->next();
        } catch (RuntimeException $e) {
            $this->logger->error('job queue cursor failed to retrieve next job, restart daemon', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            $this->process();
        }
    }

    /**
     * Queue job.
     *
     * @param array $job
     */
    protected function queueJob(array $job): bool
    {
        if (true === $this->collectJob($job['_id'], self::STATUS_PROCESSING)) {
            $this->processJob($job);
        } elseif (self::STATUS_POSTPONED === $job['status']) {
            $this->logger->debug('found postponed job ['.$job['_id'].'] to requeue', [
                'category' => get_class($this),
            ]);

            $this->queue[] = $job;
        }

        return true;
    }

    /**
     * Get cursor.
     *
     * @param bool $tailable
     *
     * @return IteratorIterator
     */
    protected function getCursor(bool $tailable = true): IteratorIterator
    {
        $options = ['typeMap' => Scheduler::TYPE_MAP];

        if (true === $tailable) {
            $options['cursorType'] = Find::TAILABLE;
            $options['noCursorTimeout'] = true;
        }

        try {
            $cursor = $this->db->{$this->collection_name}->find([
                '$or' => [
                    ['status' => self::STATUS_WAITING],
                    ['status' => self::STATUS_POSTPONED],
                ],
            ], $options);
        } catch (ConnectionException | ServerException $e) {
            if (2 === $e->getCode()) {
                $this->convertQueue();

                return $this->getCursor($tailable);
            }

            throw $e;
        }

        $iterator = new IteratorIterator($cursor);
        $iterator->rewind();

        return $iterator;
    }

    /**
     * Update job status.
     *
     * @param ObjectId $id
     * @param int      $status
     * @param mixed    $from_status
     *
     * @return bool
     */
    protected function collectJob(ObjectId $id, int $status, $from_status = self::STATUS_WAITING): bool
    {
        $set = [
             'status' => $status,
        ];

        if (self::STATUS_PROCESSING === $status) {
            $set['started'] = new UTCDateTime();
        }

        $result = $this->db->{$this->collection_name}->updateMany([
            '_id' => $id,
            'status' => $from_status,
            '$isolated' => true,
        ], [
            '$set' => $set,
        ]);

        if (1 === $result->getModifiedCount()) {
            $this->logger->debug('job ['.$id.'] updated to status ['.$status.']', [
                'category' => get_class($this),
            ]);

            return true;
        }

        $this->logger->debug('job ['.$id.'] is already collected with status ['.$status.']', [
            'category' => get_class($this),
        ]);

        return false;
    }

    /**
     * Update job status.
     *
     * @param ObjectId $id
     * @param int      $status
     *
     * @return bool
     */
    protected function updateJob(ObjectId $id, int $status): bool
    {
        $set = [
            'status' => $status,
        ];

        if ($status >= self::STATUS_DONE) {
            $set['ended'] = new UTCDateTime();
        }

        $result = $this->db->{$this->collection_name}->updateMany([
            '_id' => $id,
            '$isolated' => true,
        ], [
            '$set' => $set,
        ]);

        return $result->isAcknowledged();
    }

    /**
     * Check local queue for postponed jobs.
     *
     * @return bool
     */
    protected function processLocalQueue(): bool
    {
        $now = new UTCDateTime();
        foreach ($this->queue as $key => $job) {
            if ($job['at'] <= $now) {
                $this->logger->info('postponed job ['.$job['_id'].'] ['.$job['class'].'] can now be executed', [
                    'category' => get_class($this),
                ]);

                unset($this->queue[$key]);
                $job['at'] = null;

                if (true === $this->collectJob($job['_id'], self::STATUS_PROCESSING, self::STATUS_POSTPONED)) {
                    $this->processJob($job);
                }
            }
        }

        return true;
    }

    /**
     * Process job.
     *
     * @param array $job
     *
     * @return ObjectId
     */
    protected function processJob(array $job): ObjectId
    {
        if ($job['at'] instanceof UTCDateTime) {
            $this->updateJob($job['_id'], self::STATUS_POSTPONED);
            $this->queue[] = $job;

            $this->logger->debug('execution of job ['.$job['_id'].'] ['.$job['class'].'] is postponed at ['.$job['at']->toDateTime()->format('c').']', [
                'category' => get_class($this),
            ]);

            return $job['_id'];
        }

        $this->logger->debug('execute job ['.$job['_id'].'] ['.$job['class'].']', [
            'category' => get_class($this),
            'params' => $job['data'],
        ]);

        $this->current_job = $job;

        try {
            $this->executeJob($job);
            $this->current_job = null;
        } catch (\Exception $e) {
            $this->logger->error('failed execute job ['.$job['_id'].']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            $this->updateJob($job['_id'], self::STATUS_FAILED);
            $this->current_job = null;

            if ($job['retry'] >= 0) {
                $this->logger->debug('failed job ['.$job['_id'].'] has a retry interval of ['.$job['retry'].']', [
                    'category' => get_class($this),
                ]);

                return $this->scheduler->addJob($job['class'], $job['data'], [
                    Scheduler::OPTION_AT => time() + $job['retry_interval'],
                    Scheduler::OPTION_INTERVAL => $job['interval'],
                    Scheduler::OPTION_RETRY => --$job['retry'],
                    Scheduler::OPTION_RETRY_INTERVAL => $job['retry_interval'],
                ]);
            }
        }

        if ($job['interval'] >= 0) {
            $this->logger->debug('job ['.$job['_id'].'] has an interval of ['.$job['interval'].'s]', [
                'category' => get_class($this),
            ]);

            return $this->scheduler->addJob($job['class'], $job['data'], [
                Scheduler::OPTION_AT => time() + $job['interval'],
                Scheduler::OPTION_INTERVAL => $job['interval'],
                Scheduler::OPTION_RETRY => $job['retry'],
                Scheduler::OPTION_RETRY_INTERVAL => $job['retry_interval'],
            ]);
        }

        return $job['_id'];
    }

    /**
     * Execute job.
     *
     * @param array $job
     *
     * @return bool
     */
    protected function executeJob(array $job): bool
    {
        if (!class_exists($job['class'])) {
            throw new Exception\InvalidJob('job class does not exists');
        }

        if (null === $this->container) {
            $instance = new $job['class']();
        } else {
            $instance = $this->container->get($job['class']);
        }

        if (!($instance instanceof JobInterface)) {
            throw new Exception\InvalidJob('job must implement JobInterface');
        }

        $instance
            ->setData($job['data'])
            ->setId($job['_id'])
            ->start();

        return $this->updateJob($job['_id'], self::STATUS_DONE);
    }
}
