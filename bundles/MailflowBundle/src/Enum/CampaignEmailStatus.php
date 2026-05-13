<?php

namespace Ephp\MailflowBundle\Enum;

enum CampaignEmailStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
}
