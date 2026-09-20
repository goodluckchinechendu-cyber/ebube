<?php

/**
 * Fetch one row from a prepared statement (works without mysqlnd).
 */
function mysqli_stmt_fetch_assoc(mysqli_stmt $stmt): ?array
{
    if (method_exists($stmt, 'get_result')) {
        $result = @$stmt->get_result();
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            return $row ?: null;
        }
    }

    $meta = $stmt->result_metadata();
    if (!$meta instanceof mysqli_result) {
        return null;
    }

    $fields = $meta->fetch_fields();
    $meta->free();

  if ($fields === []) {
    return null;
  }

    $row = [];
    $bind = [];
    foreach ($fields as $field) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    if (!$stmt->fetch()) {
        return null;
    }

    $copy = [];
    foreach ($row as $key => $value) {
        $copy[$key] = $value;
    }

    return $copy;
}

function mysqli_fetch_user_by_id(mysqli $mysqli, int $userId): ?array
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }

    $result = $mysqli->query("SELECT * FROM `users` WHERE `id` = $userId LIMIT 1");
    if (!$result instanceof mysqli_result) {
        return null;
    }

    $row = $result->fetch_assoc();
    $result->free();

    return $row ?: null;
}

function mysqli_fetch_user_for_login(mysqli $mysqli, string $login, bool $isEmail): ?array
{
    if ($isEmail) {
        $sql = "SELECT `id`, `email`, `password_hash`
                FROM `users`
                WHERE LOWER(TRIM(`email`)) = ?
                LIMIT 1";
    } else {
        $sql = "SELECT `id`, `email`, `password_hash`
                FROM `users`
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '+', ''), '.', '') = ?
                LIMIT 1";
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $login);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $row = mysqli_stmt_fetch_assoc($stmt);
    $stmt->close();

    return $row;
}
