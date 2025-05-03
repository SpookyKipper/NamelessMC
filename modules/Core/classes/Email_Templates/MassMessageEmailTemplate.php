<?php

class MassMessageEmailTemplate extends EmailTemplate
{
    public const ID = 6;

    public function __construct(string $content)
    {
        $this->addPlaceholder('[Message]', $content);

        parent::__construct();
    }

    public function id(): int
    {
        return self::ID;
    }

    public function subject(): LanguageKey
    {
        return new LanguageKey('admin', 'mass_message');
    }
}
