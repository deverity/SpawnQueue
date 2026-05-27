<?php

declare(strict_types=1);

namespace SpawnQueue\Handler;

use Cake\Core\Configure;
use Cake\Mailer\Mailer;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

/**
 * Native SpawnQueue handler for asynchronous email delivery.
 *
 * This intentionally focuses on the JSON-safe subset of the old Queue.Email
 * payload contract, which is the only part that reliably survives table-backed
 * queue serialization.
 *
 * Supported payload keys:
 *   settings   array|Message Required. Mailer setters or a full legacy Message object.
 *   content    string  Optional email body content for deliver().
 *   vars       array   Optional view vars.
 *   headers    array   Optional extra headers.
 *   transport  string  Optional mail transport name.
 *   mailer_class string Optional concrete Cake mailer class to instantiate.
 *   action     string  Optional mailer action to call via send().
 */
class EmailJobHandler implements JobHandlerInterface
{
    /**
     * Queue used by the native email handler.
     */
    public static function queue(): string
    {
        return 'emails';
    }

    public function handle(JobData $job): JobResult
    {
        $payload = $job->payload;
        $settings = $payload['settings'] ?? null;

        if ($settings === null) {
            throw new NonRetryableJobException('Queue email job requires settings data.');
        }

        if (isset($payload['vars']) && !is_array($payload['vars'])) {
            throw new NonRetryableJobException('Queue email job expects vars as array.');
        }

        if (isset($payload['headers']) && !is_array($payload['headers'])) {
            throw new NonRetryableJobException('Queue email job expects headers as array.');
        }

        try {
            if ($settings instanceof Message) {
                $this->sendLegacyMessage($settings, $payload);

                return JobResult::success();
            }

            if (!is_array($settings) || $settings === []) {
                throw new NonRetryableJobException('Queue email job requires a non-empty settings array.');
            }

            $mailer = $this->buildMailer($payload);
            $this->applySettings($mailer, $settings);

            if (!empty($payload['vars']) && is_array($payload['vars'])) {
                $mailer->setViewVars($payload['vars']);
            }

            if (!empty($payload['headers']) && is_array($payload['headers'])) {
                $mailer->getMessage()->setHeaders($payload['headers']);
            }

            if (!empty($payload['transport']) && is_string($payload['transport'])) {
                $mailer->setTransport($payload['transport']);
            }

            if (!empty($payload['action']) && is_string($payload['action'])) {
                $args = [];
                if (!empty($payload['vars']) && is_array($payload['vars'])) {
                    $args[] = $payload['vars'];
                }
                $mailer->send($payload['action'], $args);
            } else {
                $mailer->deliver((string) ($payload['content'] ?? ''));
            }
        } catch (NonRetryableJobException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RetryableJobException(
                sprintf('Email delivery failed: [%s] %s', $e::class, $e->getMessage()),
                previous: $e
            );
        }

        return JobResult::success();
    }

    private function sendLegacyMessage(Message $message, array $payload): void
    {
        $transportName = !empty($payload['transport']) && is_string($payload['transport'])
            ? $payload['transport']
            : 'default';

        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            $message->setHeaders($payload['headers']);
        }

        $transport = TransportFactory::get($transportName);
        $result = $transport->send($message);

        if (empty($result)) {
            throw new RetryableJobException('Legacy email transport returned an empty result.');
        }
    }

    private function buildMailer(array $payload): Mailer
    {
        $mailerClass = $payload['mailer_class'] ?? Configure::read('Queue.mailerClass') ?? Mailer::class;

        if (!is_string($mailerClass) || $mailerClass === '') {
            throw new NonRetryableJobException('Invalid mailer_class value for queue email job.');
        }

        if (!class_exists($mailerClass)) {
            throw new NonRetryableJobException("Configured mailer class not found: {$mailerClass}");
        }

        if (!is_a($mailerClass, Mailer::class, true)) {
            throw new NonRetryableJobException("Configured mailer class must extend " . Mailer::class);
        }

        return new $mailerClass();
    }

    private function applySettings(Mailer $mailer, array $settings): void
    {
        foreach ($settings as $method => $value) {
            if (!is_string($method) || $method === '') {
                continue;
            }

            if (in_array($method, ['theme', 'template', 'layout'], true)) {
                $setter = 'set' . ucfirst($method);
                $mailer->viewBuilder()->{$setter}($value);
                continue;
            }

            if ($method === 'helper') {
                if (!is_array($value) || $this->isList($value)) {
                    throw new NonRetryableJobException('Email helper setting must be an associative array.');
                }

                foreach ($value as $helper => $options) {
                    $mailer->viewBuilder()->addHelper((string) $helper, is_array($options) ? $options : []);
                }
                continue;
            }

            if ($method === 'helpers') {
                if (!is_array($value)) {
                    throw new NonRetryableJobException('Email helpers setting must be an array.');
                }

                $mailer->viewBuilder()->addHelpers($value);
                continue;
            }

            $setter = 'set' . ucfirst($method);
            if (!method_exists($mailer, $setter)) {
                throw new NonRetryableJobException("Unsupported email setting: {$method}");
            }

            if (is_array($value)) {
                if ($this->isList($value)) {
                    $mailer->{$setter}(...$value);
                } else {
                    $mailer->{$setter}($value);
                }
            } else {
                $mailer->{$setter}($value);
            }
        }
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
