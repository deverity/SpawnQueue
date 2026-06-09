<?php

declare(strict_types=1);

namespace SpawnQueue\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property string      $worker_id
 * @property string      $queue
 * @property string      $host
 * @property int|null    $pid
 * @property string      $status
 * @property \Cake\I18n\FrozenTime|null $started_at
 * @property \Cake\I18n\FrozenTime|null $heartbeat_at
 * @property \Cake\I18n\FrozenTime|null $stopped_at
 * @property \Cake\I18n\FrozenTime      $created
 * @property \Cake\I18n\FrozenTime      $modified
 */
class QueueProcess extends Entity
{
    protected $_accessible = [
        'worker_id'    => true,
        'queue'        => true,
        'host'         => true,
        'pid'          => true,
        'status'       => true,
        'started_at'   => true,
        'heartbeat_at' => true,
        'stopped_at'   => true,
        'created'      => true,
        'modified'     => true,
    ];
}
