<?php

class TestEmailTemplate extends EmailTemplate
{
    public const ID = 7;

    public function __construct()
    {
        $this->addPlaceholder('[Message]', new LanguageKey('emails', 'test_message'));

        parent::__construct();
    }

    public function id(): int
    {
        return self::ID;
    }

    public function subject(): LanguageKey
    {
        return new LanguageKey('emails', 'test_subject');
    }
}
