<?php

class NotificationEmailTemplate extends EmailTemplate
{
    private string $subject;

    public function __construct(string $subject, string $content, ?string $link)
    {
        $this->subject = $subject;

        $this->addPlaceholder('[Title]', $subject);
        $this->addPlaceholder('[Content]', $content);
        $this->addPlaceholder('[Link]', $link);

        parent::__construct();
    }

    public function subject(): string
    {
        return $this->subject;
    }
}