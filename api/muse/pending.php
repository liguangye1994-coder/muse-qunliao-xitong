<?php
/**
 * Muse 待审核提醒接口
 *   GET /api/muse/pending.php
 *
 * 返回还没提醒过的待审核用户消息（review_status='pending' 且 is_muse_reply=0），
 * 按 id 升序，最多 50 条；返回后立即把它们标为已提醒（reminded=1），
 * 这样群里多个 Muse（助手……）轮询时，只有第一个取到的会发提醒，
 * 不会一人发一条、重复刷屏。
 * 响应里只有 id、username、created_at，不带 content——未过审的内容不外传，
 * Muse 只拿条数和发信人来发提醒，提醒本身也不贴原文。
 * 提醒由 Muse 发：调 reply.php 发一条回帖（不带 reply_to_id），例如
 * “有 2 条新消息待审核（小明、小红），请在群里点通过/不通过。”；
 * 管理员直接在群聊页点通过/不通过就行，不用回 Muse 界面。
 * 提醒发出前管理员已经审过的，不会再出现在这里（已不是 pending）。
 *
 * 认证：请求头 X-Muse-Token 或 Authorization: Bearer <token>，同 inbox.php。
 * 前置：须先在库里跑 migrations/001-add-reminded.sql 加 reminded 列，
 * 否则本接口报 500。PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('ok' => false, 'error' => '只支持 GET'), 405);
}
if (!muse_token_valid()) {
    json_out(array('ok' => false, 'error' => 'token 无效'), 401);
}

try {
    $pdo = db_pdo();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT m.id, m.username, m.created_at
         FROM messages m
         WHERE m.review_status = 'pending'
           AND m.is_muse_reply = 0
           AND m.reminded = 0
         ORDER BY m.id ASC
         LIMIT 50
         FOR UPDATE"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!empty($rows)) {
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int)$r['id'];
        }
        $marks = $pdo->prepare(
            "UPDATE messages SET reminded = 1 WHERE id IN (" . implode(',', $ids) . ")"
        );
        $marks->execute();
    }
    $pdo->commit();
    json_out(array('ok' => true, 'messages' => $rows));
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(array('ok' => false, 'error' => '读取失败'), 500);
}
