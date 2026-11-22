<?php

use App\Providers\AppServiceProvider;
use App\Providers\BackupSettingsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\MailSettingsServiceProvider;
use App\Providers\MonitoringServiceProvider;

return [
    AppServiceProvider::class,
    BackupSettingsServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    MailSettingsServiceProvider::class,
    MonitoringServiceProvider::class,
];
