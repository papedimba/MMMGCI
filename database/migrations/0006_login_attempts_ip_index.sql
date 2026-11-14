ALTER TABLE login_attempts ADD KEY idx_login_attempts_ip_created (ip, created_at);
