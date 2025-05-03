<?php

class ReportCreatedEmailTemplate extends EmailTemplate
{
    public const ID = 8;

    public function __construct(string $link, User $reported, User $author)
    {
        $this->addPlaceholder('[Link]', $link);
        $this->addPlaceholder('[Message]', new LanguageKey('emails', 'report_message', [
            'reported' => $reported->getDisplayname(),
            'author' => $author->getDisplayname(),
        ]));

        parent::__construct();
    }

    public function id(): int
    {
        return self::ID;
    }

    public function subject(): LanguageKey
    {
        return new LanguageKey('emails', 'report_subject');
    }
}
