<?php

class MassMessage extends Task {

    public function run(): string {
        $limit = 20;

        $start = $this->getFragmentNext() ?? 0;
        $end = $start + $limit;
        $nextStatus = Task::STATUS_READY;

        if ($end > $this->getFragmentTotal()) {
            $end = $this->getFragmentTotal();
            $nextStatus = Task::STATUS_COMPLETED;
        }

        $where = '';
        $whereVars = [];
        if (!empty($this->getData()['users'])) {
            $whereIn = implode(',', array_map(static fn ($u) => '?', $this->getData()['users']));
            $where = "WHERE id IN ($whereIn)";
            $whereVars = array_map(static fn ($u) => $u['id'], $this->getData()['users']);
        }

        $recipients = DB::getInstance()->query(
            <<<SQL
            SELECT `id`
            FROM nl2_users
            $where
            LIMIT $start, 50
            SQL,
            $whereVars
        );

        $content = $this->getData()['content'];
        $title = $this->getData()['title'];
        $skipPurify = $this->getData()['skip_purify'] ?? false;

        $event = new GenerateNotificationContentEvent($content, $title, $skipPurify);
        EventHandler::executeEvent($event);
        $content = $event->content;

        $notification = new Notification(
            'mass_message',
            new AlertTemplate(
                new LanguageKey('admin', 'mass_message'),
                $content,
            ),
            new MassMessageEmailTemplate(
                $content,
            ),
            array_map(static fn ($r) => $r->id, $recipients->results()),
            $this->getUserId(),
        );
        $notification->send();

        $this->setOutput(['userIds' => $whereVars, 'start' => $start, 'end' => $end, 'next_status' => $nextStatus]);
        $this->setFragmentNext($end);

        return $nextStatus;
    }
}
