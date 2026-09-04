<?php

use App\Jobs\AgendarAlertasPrazoJob;
use App\Jobs\DigestDiarioJob;
use App\Jobs\DispararNotificacoesJob;
use App\Jobs\RevisarCalendarioJob;
use App\Jobs\SyncPublicacoesJob;
use App\Models\OabWatch;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler da secao 10. Toda janela e America/Sao_Paulo: as datas do DJEN sao
 * nesse fuso e rodar em UTC produz janela errada.
 */
$tz = config('mithrandir.timezone');

// 06:00 - varredura do DJEN, um job por termo de vigilancia (RF-1.2).
Schedule::call(function () {
    OabWatch::where('ativo', true)
        ->pluck('id')
        ->each(fn (int $id) => SyncPublicacoesJob::dispatch($id));
})->dailyAt('06:00')->timezone($tz)->name('djen:varredura')->withoutOverlapping();

// 06:30 - depois da varredura, reavalia as antecedencias D-10..D-0 (RF-8.1).
Schedule::job(new AgendarAlertasPrazoJob)
    ->dailyAt('06:30')
    ->timezone($tz)
    ->name('prazos:alertas');

// De hora em hora - digest de quem escolheu esta hora (RF-8.2) e entrega da fila.
Schedule::job(new DigestDiarioJob)->hourly()->timezone($tz)->name('digest:diario');
Schedule::job(new DispararNotificacoesJob)->hourly()->name('notificacoes:disparar');

// Semanal - lembrete de revisao do calendario de feriados (secao 8.4).
Schedule::job(new RevisarCalendarioJob)
    ->weeklyOn(1, '07:00')
    ->timezone($tz)
    ->name('calendario:revisar');
