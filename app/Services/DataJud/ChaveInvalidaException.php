<?php

namespace App\Services\DataJud;

use RuntimeException;

/**
 * 401 do DataJud: o CNJ trocou a chave publica (secao 7.2). Nao e erro de
 * codigo, e config em runtime - por isso vira alerta ao usuario, nao 500.
 */
class ChaveInvalidaException extends RuntimeException {}
