-- ============================================================
-- IP探针 数据库一键安装脚本（全新部署专用）
-- 包含全部最新表结构（含 v1.5.x 全部字段），幂等可重复执行。
-- 用法：安装向导自动导入；或宝塔面板 -> 数据库 -> 选择库 -> 导入本文件
-- ============================================================
CREATE TABLE IF NOT EXISTS `probes` (
    `code`          VARCHAR(16)  NOT NULL COMMENT '短码，主键',
    `name`          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '探针名称',
    `redirect`      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '跳转地址（redirect 模板）',
    `template`      VARCHAR(16)  NOT NULL DEFAULT 'blank' COMMENT '访问模板: blank/fake404/fake503/image/text/redirect/weather',
    `image_url`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '图片页图片URL（image 模板）',
    `text_content`  VARCHAR(2000) NOT NULL DEFAULT '' COMMENT '文字页内容（text 模板）',
    `short_url`     VARCHAR(200) NOT NULL DEFAULT '' COMMENT '生成的短链',
    `short_provider` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '短链服务商: self/tinyurl/clckru',
    `access_code`   VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '访问码（空=无需验证）',
    `expire_days`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有效期天数（0=永久有效）',
    `once_only`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否一次性（同IP仅首次记录）',
    `enabled`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否启用（0=链接失效）',
    `visit_count`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点击次数',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `last_visit_at` DATETIME     NULL DEFAULT NULL COMMENT '最近点击时间',
    `updated_at`    DATETIME     NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`code`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '探针表';
CREATE TABLE IF NOT EXISTS `visit_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `probe`      VARCHAR(16)  NOT NULL COMMENT '所属探针短码',
    `ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '访客IP（支持IPv6）',
    `country`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '国家',
    `province`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '省',
    `city`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '市',
    `isp`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '运营商',
    `lat`        DECIMAL(9,6) NULL DEFAULT NULL COMMENT '纬度',
    `lng`        DECIMAL(9,6) NULL DEFAULT NULL COMMENT '经度',
    `zip`        VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '邮编',
    `timezone`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '时区',
    `org`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '组织',
    `as_info`    VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'ASN/AS号',
    `gps_lat`    DECIMAL(9,6) NULL DEFAULT NULL COMMENT 'GPS纬度（访客授权上报）',
    `gps_lng`    DECIMAL(9,6) NULL DEFAULT NULL COMMENT 'GPS经度（访客授权上报）',
    `gps_acc`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'GPS精度（米）',
    `district`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '区县',
    `gps_addr`   VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'GPS逆地理编码地址',
    `is_ipv6`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否IPv6',
    `ua`         VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'User-Agent',
    `referer`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '来源页',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
    PRIMARY KEY (`id`),
    KEY `idx_probe_id` (`probe`, `id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '访问日志表';
CREATE TABLE IF NOT EXISTS `geo_cache` (
    `ip`         VARCHAR(45)  NOT NULL COMMENT 'IP（支持IPv6）',
    `data`       MEDIUMTEXT   NOT NULL COMMENT '定位结果JSON',
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`ip`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定位缓存表';
-- 安装完成
