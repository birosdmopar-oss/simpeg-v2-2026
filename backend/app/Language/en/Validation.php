<?php

declare(strict_types=1);

// override core en language system validation or define your own en language validation message
return [
    // Rule App\Validation\ByteRules (DBV-002). Pemakai rule biasanya mengirim pesan sendiri; ini cadangan.
    'max_byte_length' => '{field} maksimal {param} byte.',
    // Rule `string` bawaan CI4 (StrictRules menolak angka/array). Disamakan dengan
    // ApiController::NON_TEXT_FIELD_MESSAGE agar semua jalur memakai pesan Bahasa Indonesia yang sama (CR-038, F-R1-1).
    'string' => 'Isian harus berupa teks.',
];
