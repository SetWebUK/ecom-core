<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;

/** Abandoned-cart reminder emails (Settings › Abandoned carts) – see AbandonedCartRecovery. */
class SendAbandonedCartEmails extends Task
{
    public function __construct(protected AbandonedCartRecovery $recovery)
    {
    }

    public function skipReason(): ?string
    {
        return $this->recovery->disabledReason();
    }

    public function handle(): string
    {
        $result = $this->recovery->sendDue();

        return $result['sent'].' '.str('reminder')->plural($result['sent']).' sent'
            .($result['failed'] ? ', '.$result['failed'].' failed (see the log)' : '')
            .($result['stopped'] ? ', '.$result['stopped'].' '.str('sequence')->plural($result['stopped']).' stopped' : '');
    }
}
