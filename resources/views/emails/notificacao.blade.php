<x-mail::message>
# {{ $titulo }}

{{ $corpo }}

<x-mail::button :url="$url">
Abrir no Mithrandir
</x-mail::button>

Voce recebeu este e-mail porque a notificacao por push nao chegou ao seu
dispositivo. Confira se o app esta instalado na tela de inicio.

*O Mithrandir nao substitui a conferencia do diario oficial.*
</x-mail::message>
