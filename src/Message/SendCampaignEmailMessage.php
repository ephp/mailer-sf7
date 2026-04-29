<?php

namespace App\Message;

class SendCampaignEmailMessage
{
    public function __construct(
        private readonly int $campaignEmailId,
    ) {}

    public function getCampaignEmailId(): int
    {
        return $this->campaignEmailId;
    }
}
