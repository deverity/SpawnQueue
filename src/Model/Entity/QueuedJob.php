<?php

declare(strict_types=1);

namespace SpawnQueue\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int         $id
 * @property string|null $queue
 * @property string      $job_task
 * @property string|null $data
 * @property string|null $job_group
 * @property string|null $reference
 * @property \Cake\I18n\FrozenTime      $created
 * @property \Cake\I18n\FrozenTime|null $notbefore
 * @property \Cake\I18n\FrozenTime|null $fetched
 * @property \Cake\I18n\FrozenTime|null $completed
 * @property float|null  $progress
 * @property int         $failed
 * @property int         $max_attempts
 * @property string|null $failure_message
 * @property string|null $workerkey
 * @property int|null    $pid
 * @property string|null $status
 * @property int         $priority
 * @property \Cake\I18n\FrozenTime|null $failed_at
 */
class QueuedJob extends Entity
{
    protected $_accessible = [
        'queue'           => true,
        'job_task'        => true,
        'data'            => true,
        'job_group'       => true,
        'reference'       => true,
        'created'         => true,
        'notbefore'       => true,
        'fetched'         => true,
        'completed'       => true,
        'progress'        => true,
        'failed'          => true,
        'max_attempts'    => true,
        'failure_message' => true,
        'workerkey'       => true,
        'pid'             => true,
        'status'          => true,
        'priority'        => true,
        'failed_at'       => true,
    ];
}
