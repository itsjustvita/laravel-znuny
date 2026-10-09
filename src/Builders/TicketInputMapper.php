<?php

namespace ItsJustVita\LaravelZnuny\Builders;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Attachment;

class TicketInputMapper
{
    /** @var array<string, string> */
    private const TICKET_KEYS = [
        'title' => 'Title',
        'queueId' => 'QueueID',
        'queue' => 'Queue',
        'state' => 'State',
        'stateId' => 'StateID',
        'priority' => 'Priority',
        'priorityId' => 'PriorityID',
        'type' => 'Type',
        'typeId' => 'TypeID',
        'customerUser' => 'CustomerUser',
        'customerUserID' => 'CustomerUserID',
        'customerUserId' => 'CustomerUserID',
        'customerId' => 'CustomerID',
        'service' => 'Service',
        'serviceId' => 'ServiceID',
        'sla' => 'SLA',
        'slaId' => 'SLAID',
        'owner' => 'Owner',
        'ownerId' => 'OwnerID',
        'responsible' => 'Responsible',
        'responsibleId' => 'ResponsibleID',
        'lock' => 'Lock',
        'lockId' => 'LockID',
        'pendingTime' => 'PendingTime',
    ];

    /** @var array<string, string> */
    private const ARTICLE_KEYS = [
        'subject' => 'Subject',
        'body' => 'Body',
        'contentType' => 'ContentType',
        'mimeType' => 'MimeType',
        'charset' => 'Charset',
        'communicationChannel' => 'CommunicationChannel',
        'communicationChannelId' => 'CommunicationChannelID',
        'senderType' => 'SenderType',
        'senderTypeId' => 'SenderTypeID',
        'isVisibleForCustomer' => 'IsVisibleForCustomer',
        'from' => 'From',
        'to' => 'To',
        'cc' => 'Cc',
        'bcc' => 'Bcc',
        'replyTo' => 'ReplyTo',
        'timeUnit' => 'TimeUnit',
        'historyType' => 'HistoryType',
        'historyComment' => 'HistoryComment',
        'noAgentNotify' => 'NoAgentNotify',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function mapTicket(array $input): array
    {
        return self::map($input, self::TICKET_KEYS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function mapArticle(array $input): array
    {
        return self::map($input, self::ARTICLE_KEYS);
    }

    /**
     * @param  array<string, mixed>  $input  name => value
     * @return list<array{Name: string, Value: mixed}>
     */
    public static function mapDynamicFields(array $input): array
    {
        $fields = [];

        foreach ($input as $name => $value) {
            $fields[] = ['Name' => (string) $name, 'Value' => is_bool($value) ? (int) $value : $value];
        }

        return $fields;
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return list<array{Filename: string, ContentType: string, Content: string}>
     */
    public static function mapAttachments(array $attachments): array
    {
        return array_map(function (mixed $attachment): array {
            if (! $attachment instanceof Attachment) {
                throw new InvalidArgumentException('Attachments must be instances of '.Attachment::class.'.');
            }

            return $attachment->toZnunyPayload();
        }, array_values($attachments));
    }

    /**
     * Map camelCase keys to Znuny keys; unknown keys pass through, booleans become 1/0.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    private static function map(array $input, array $keys): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            $result[$keys[$key] ?? $key] = is_bool($value) ? (int) $value : $value;
        }

        return $result;
    }
}
