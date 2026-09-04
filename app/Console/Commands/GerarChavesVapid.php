<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Web Push custa zero, mas exige um par de chaves VAPID próprio (secao 7.3).
 * Sem elas o app calcula prazo e nao avisa ninguem.
 */
class GerarChavesVapid extends Command
{
    protected $signature = 'mithrandir:vapid {--escrever : Grava as chaves no .env}';

    protected $description = 'Gera o par de chaves VAPID usado pelo Web Push';

    public function handle(): int
    {
        if (! $this->openSslGeraChaveEc()) {
            $this->error('O OpenSSL desta instalacao nao consegue gerar chave de curva eliptica.');
            $this->newLine();
            $this->line('Quase sempre e a variavel OPENSSL_CONF apontando para lugar nenhum (comum no Windows).');
            $this->line('Aponte-a para o openssl.cnf que vem com o PHP e rode de novo:');
            $this->newLine();
            $this->line('  <fg=gray>setx OPENSSL_CONF "C:\Program Files\PHP\current\extras\ssl\openssl.cnf"</>');
            $this->newLine();
            $this->comment('Sem isso o Web Push tambem falha em tempo de envio, nao so aqui.');

            return self::FAILURE;
        }

        $chaves = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('Chaves VAPID geradas.');
        $this->newLine();

        $linhas = [
            'VAPID_SUBJECT' => config('mithrandir.push.vapid_subject'),
            'VAPID_PUBLIC_KEY' => $chaves['publicKey'],
            'VAPID_PRIVATE_KEY' => $chaves['privateKey'],
        ];

        foreach ($linhas as $chave => $valor) {
            $this->line("<fg=gray>{$chave}=</>{$valor}");
        }

        $this->newLine();

        if (! $this->option('escrever')) {
            $this->comment('Copie as linhas acima para o .env, ou rode de novo com --escrever.');

            return self::SUCCESS;
        }

        $caminho = base_path('.env');
        $conteudo = file_get_contents($caminho);

        foreach ($linhas as $chave => $valor) {
            $conteudo = preg_match("/^{$chave}=.*$/m", $conteudo)
                ? preg_replace("/^{$chave}=.*$/m", "{$chave}={$valor}", $conteudo)
                : rtrim($conteudo)."\n{$chave}={$valor}";
        }

        file_put_contents($caminho, rtrim($conteudo)."\n");

        $this->info('.env atualizado. Rode "php artisan config:clear" e reinicie a aplicacao.');

        return self::SUCCESS;
    }

    /**
     * O Web Push depende de ECDH (curva prime256v1) tanto para gerar as chaves
     * VAPID quanto para cifrar cada mensagem enviada. Se isso nao funciona,
     * falhar aqui com uma mensagem util e melhor do que falhar calado no envio.
     */
    private function openSslGeraChaveEc(): bool
    {
        $chave = @openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        while (openssl_error_string() !== false) {
            // Esvazia a fila de erros para nao contaminar chamadas seguintes.
        }

        return $chave !== false;
    }
}
