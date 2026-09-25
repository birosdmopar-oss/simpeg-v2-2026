<?php

declare(strict_types=1);

// override core en language system validation or define your own en language validation message
return [
    // Rule App\Validation\ByteRules (DBV-002). Pemakai rule biasanya mengirim pesan sendiri; ini cadangan.
    'max_byte_length' => '{field} maksimal {param} byte.',
];
