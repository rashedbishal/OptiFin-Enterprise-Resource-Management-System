<?php

namespace App\Notifications\Backups;

use App\Notifications\Backups\Concerns\SendsThroughAlertsAccount;
use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification as Base;

/**
 * The backup package's own email, sent through the "Monitoring alerts" account
 * (Admin → Settings → Email settings) when one is saved. Same class name as
 * the package's, which is how its event handler finds it in config/backup.php.
 */
class CleanupHasFailedNotification extends Base
{
    use SendsThroughAlertsAccount;

    public function toMail(): MailMessage
    {
        return $this->throughAlertsAccount(parent::toMail());
    }
}
