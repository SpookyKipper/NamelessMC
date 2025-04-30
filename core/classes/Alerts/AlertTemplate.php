<?php

class AlertTemplate
{
    public function __construct(
        public LanguageKey $title,
        public ?LanguageKey $content,
        public ?string $link = null,
    ) {
        if ($this->link === null && $this->content === null) {
            throw new InvalidArgumentException('Either link or content must be provided');
        }
    }
}
