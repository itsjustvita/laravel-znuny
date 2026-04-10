<?php

namespace ItsJustVita\LaravelZnuny\Builders;

class TicketInputMapper
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function mapTicket(array $input): array
    {
        $map = [
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
            'customerId' => 'CustomerID',
            'serviceId' => 'ServiceID',
        ];

        $result = [];
        foreach ($input as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function mapArticle(array $input): array
    {
        $map = [
            'subject' => 'Subject',
            'body' => 'Body',
            'contentType' => 'ContentType',
            'communicationChannel' => 'CommunicationChannel',
            'senderType' => 'SenderType',
            'from' => 'From',
            'timeUnit' => 'TimeUnit',
        ];

        $result = [];
        foreach ($input as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input  name => value
     * @return array<int, array{Name: string, Value: mixed}>
     */
    public static function mapDynamicFields(array $input): array
    {
        $out = [];
        foreach ($input as $name => $value) {
            $out[] = ['Name' => $name, 'Value' => $value];
        }

        return $out;
    }
}
