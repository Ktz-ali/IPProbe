<?php
/**
 * 模板渲染测试：每个模板在子进程中执行（render_probe_page 会 exit）
 * 运行: php test_render.php
 */
$cases = [
    'blank'    => ['template' => 'blank'],
    'fake404'  => ['template' => 'fake404'],
    'fake503'  => ['template' => 'fake503'],
    'image'    => ['template' => 'image', 'image_url' => 'https://example.com/a.jpg'],
    'image_def'=> ['template' => 'image', 'image_url' => ''],
    'text'     => ['template' => 'text', 'text_content' => '你好世界'],
    'text_def' => ['template' => 'text', 'text_content' => ''],
    'redirect' => ['template' => 'redirect', 'redirect' => 'https://example.com'],
    'redirect_empty' => ['template' => 'redirect', 'redirect' => ''],
];

$expect = [
    'blank'    => '<title>.</title>',
    'fake404'  => '404 Not Found',
    'fake503'  => '503 Service',
    'image'    => '<img src="https://example.com/a.jpg"',
    'image_def'=> '图片加载中',
    'text'     => '你好世界',
    'text_def' => '这是一条测试消息',
    'redirect' => '跳转中',
    'redirect_empty' => '<title>.</title>',
];

$pass = 0;
$fail = 0;
foreach ($cases as $name => $probe) {
    $json = json_encode($probe, JSON_UNESCAPED_UNICODE);
    $phpCode = 'require __DIR__ . "/functions.php"; render_probe_page(json_decode(' . var_export($json, true) . ', true));';
    $cmd = 'php -r ' . escapeshellarg($phpCode) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out === null) {
        $out = '';
    }
    $ok = strpos($out, $expect[$name]) !== false;
    if ($ok) {
        $pass++;
        echo "PASS  template $name [" . strlen($out) . " bytes]\n";
    } else {
        $fail++;
        echo "FAIL  template $name (expect '{$expect[$name]}') got " . strlen($out) . " bytes\n";
    }
}
echo "\n===== RESULT: $pass passed, $fail failed =====\n";
exit($fail > 0 ? 1 : 0);