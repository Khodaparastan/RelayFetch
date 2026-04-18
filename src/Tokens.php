<?php
declare(strict_types=1);

function tokens_path(): string
{
    return DATA_DIR . '/tokens.json';
}

function tokens_list(): array
{
    if (!file_exists(tokens_path())) {
        return [];
    }
    $data = json_decode(file_get_contents(tokens_path()), true);
    return is_array($data) ? $data : [];
}

function tokens_save(array $tokens): void
{
    ensure_data_dir();
    file_put_contents(tokens_path(), json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
}

function tokens_add(array $post): void
{
    $tokens = tokens_list();
    $token  = trim($post['token'] ?? '') ?: bin2hex(random_bytes(24));
    $hosts  = array_filter(array_map('trim', explode(',', $post['hosts']   ?? '*')));
    $methods = array_filter(array_map('strtoupper', array_map('trim', explode(',', $post['methods'] ?? '*'))));

    $tokens[] = [
        'id'         => bin2hex(random_bytes(8)),
        'label'      => trim($post['label'] ?? 'unnamed'),
        'token'      => $token,
        'hosts'      => $hosts   ?: ['*'],
        'methods'    => $methods ?: ['*'],
        'created_at' => time(),
        'uses'       => 0,
    ];

    tokens_save($tokens);
}

function tokens_revoke(string $id): void
{
    $tokens = array_filter(tokens_list(), fn($t) => ($t['id'] ?? '') !== $id);
    tokens_save(array_values($tokens));
}

/**
 * Look up a token string and return its record, or null if not found/invalid.
 * Also increments the use counter.
 */
function tokens_verify(string $provided): ?array
{
    // Read once, update in-memory, save once to avoid race conditions.
    $all = tokens_list();
    foreach ($all as $i => $t) {
        if (hash_equals($t['token'], $provided)) {
            $all[$i]['uses'] = ($all[$i]['uses'] ?? 0) + 1;
            tokens_save($all);
            return $t;
        }
    }
    return null;
}
