<?php

namespace App\Services\Notifications;

final readonly class NotificationCenterData
{
    /**
     * @param  list<array{id: string, title: string, summary: string, category: string, created_at: string, created_at_iso: string, is_read: bool, destination_url: string, destination_label: string, read_url: string, icon: string}>  $notifications
     * @param  array{current_page: int, last_page: int, per_page: int, total: int, links: list<array{url: ?string, label: string, active: bool}>}  $pagination
     */
    public function __construct(
        public array $notifications,
        public int $unreadCount,
        public string $filter,
        public array $pagination,
    ) {}

    /** @return array{notifications: list<array{id: string, title: string, summary: string, category: string, created_at: string, created_at_iso: string, is_read: bool, destination_url: string, destination_label: string, read_url: string, icon: string}>, unread_count: int, filter: string, pagination: array{current_page: int, last_page: int, per_page: int, total: int, links: list<array{url: ?string, label: string, active: bool}>}} */
    public function toArray(): array
    {
        return [
            'notifications' => $this->notifications,
            'unread_count' => $this->unreadCount,
            'filter' => $this->filter,
            'pagination' => $this->pagination,
        ];
    }
}
