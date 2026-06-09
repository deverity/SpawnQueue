<?php

declare(strict_types=1);

namespace SpawnQueue\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class QueuedJobsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('queued_jobs');
        $this->setDisplayField('job_task');
        $this->setPrimaryKey('id');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('job_task')
            ->maxLength('job_task', 200)
            ->notEmptyString('job_task');

        $validator
            ->scalar('queue')
            ->maxLength('queue', 100)
            ->allowEmptyString('queue');

        $validator
            ->scalar('status')
            ->maxLength('status', 50)
            ->allowEmptyString('status');

        $validator
            ->integer('failed')
            ->notEmptyString('failed');

        $validator
            ->integer('max_attempts')
            ->notEmptyString('max_attempts');

        $validator
            ->integer('priority')
            ->notEmptyString('priority');

        return $validator;
    }
}
