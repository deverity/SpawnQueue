<?php

declare(strict_types=1);

namespace SpawnQueue\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class QueueProcessesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('queue_processes');
        $this->setDisplayField('worker_id');
        $this->setPrimaryKey('worker_id');

        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('worker_id')
            ->maxLength('worker_id', 100)
            ->notEmptyString('worker_id');

        $validator
            ->scalar('queue')
            ->maxLength('queue', 100)
            ->notEmptyString('queue');

        $validator
            ->scalar('host')
            ->maxLength('host', 191)
            ->notEmptyString('host');

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->notEmptyString('status');

        $validator
            ->integer('pid')
            ->allowEmptyString('pid');

        return $validator;
    }
}
