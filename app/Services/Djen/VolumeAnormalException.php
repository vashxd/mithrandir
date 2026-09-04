<?php

namespace App\Services\Djen;

use RuntimeException;

/**
 * A varredura trouxe mais do que e plausivel para um advogado solo. Sinal de
 * nome comum demais na vigilancia, ou de filtro que parou de filtrar.
 */
class VolumeAnormalException extends RuntimeException {}
