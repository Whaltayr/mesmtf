<?php

declare(strict_types=1);
//sanitizing the the strings for a safer html output, theres less eisk of 
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_ok($data = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function json_err(string $msg, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg, 'extra' => $extra]);
    exit;
}
function redirect_with_msg(string $to, string $msg = '' , string $err = '') : void   {
    $q = [];
    if($msg != '')$q['msg'] = $msg;
    if($err != '')$q['err'] = $err;
    $qs = $q ? ('?'.http_build_query($q)): '';
    header("Location: {$to}{$qs}");
    exit;
}
