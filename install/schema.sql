-- Database structure for the PHP site + e-commerce (inventory, billing, orders, customers, dues, shop).
-- Tables only, no data, except three small lookup lists at the end (Indian states, GST rates, payment methods).
-- Import into an EMPTY database:  mysql -u USER -p DBNAME < install/schema.sql
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ad_blocks` (
  `block_number` tinyint NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `ad_code` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pages` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `insertion` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disabled',
  `paragraph_number` int NOT NULL DEFAULT '1',
  `alignment` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`block_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ad_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'New Ad Slot',
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `placement` enum('header','footer','sidebar','before_content','after_content','in_article','between_posts') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_article',
  `pages` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device` enum('all','desktop','mobile') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `insertion` enum('before_paragraph','after_paragraph','na') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `paragraph_number` int NOT NULL DEFAULT '1',
  `alignment` enum('default','center','left','right','float_left','float_right') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `ad_code` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_placement` (`placement`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_config` (
  `config_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `authors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `profile_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `qualifications` text COLLATE utf8mb4_unicode_ci,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `experience` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `certifications` text COLLATE utf8mb4_unicode_ci,
  `languages_known` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instagram` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `threads` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linkedin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_posts` int DEFAULT '0',
  `is_featured` tinyint(1) DEFAULT '0',
  `status` enum('active','pending','suspended') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `user_id` int NOT NULL,
  `join_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_author_user` (`user_id`),
  CONSTRAINT `fk_author_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` text COLLATE utf8mb4_unicode_ci,
  `meta_keywords` text COLLATE utf8mb4_unicode_ci,
  `views` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  `evf` tinyint(1) DEFAULT '0',
  `status` enum('pending','approved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `post_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_date` (`date`),
  KEY `idx_post_id_date` (`post_id`,`date`),
  KEY `idx_comments_status` (`status`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecom_brands` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_popular` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_business_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_name` varchar(200) DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `seo_description` text,
  `address` text,
  `location` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `contact_numbers` text COMMENT 'JSON array of phone numbers',
  `email` varchar(190) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `business_hours` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `fssai_number` varchar(30) DEFAULT NULL COMMENT 'Optional — for food businesses',
  `show_gst_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `state` varchar(100) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `logo_display_width` int NOT NULL DEFAULT '150',
  `invoice_title` varchar(100) NOT NULL DEFAULT 'Tax Invoice',
  `invoice_footer_note` text,
  `printer_format` enum('a4','thermal_58','thermal_80') NOT NULL DEFAULT 'a4',
  `barcode_footer_text` varchar(100) DEFAULT NULL,
  `order_id_prefix` varchar(10) NOT NULL DEFAULT 'ORD',
  `order_sequence_next` int unsigned NOT NULL DEFAULT '1',
  `receipt_sequence_next` int unsigned NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `social_facebook` varchar(255) DEFAULT NULL,
  `social_instagram` varchar(255) DEFAULT NULL,
  `social_youtube` varchar(255) DEFAULT NULL,
  `social_x` varchar(255) DEFAULT NULL,
  `social_linkedin` varchar(255) DEFAULT NULL,
  `social_whatsapp` varchar(255) DEFAULT NULL,
  `return_policy` text,
  `site_header_display` enum('title','logo','both') NOT NULL DEFAULT 'both',
  `invoice_display` enum('logo','name','both') NOT NULL DEFAULT 'both',
  `show_gstin_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `show_pan_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `show_address_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `show_location_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `invoice_contact_numbers` text COMMENT 'JSON array of phone numbers to print on invoices',
  `social_media_json` text COMMENT 'JSON array of {platform,url} objects',
  `show_fssai_on_invoice` tinyint(1) NOT NULL DEFAULT '1',
  `pos_print_mode` enum('thermal','a4','both') NOT NULL DEFAULT 'both',
  `shortcut_complete_sale` varchar(5) NOT NULL DEFAULT 'F2',
  `shortcut_print` varchar(5) NOT NULL DEFAULT 'F3',
  `shortcut_new_sale` varchar(5) NOT NULL DEFAULT 'F4',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_cart_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `product_id` int NOT NULL,
  `variant_id` int NOT NULL DEFAULT '0',
  `qty` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_line` (`customer_id`,`product_id`,`variant_id`),
  UNIQUE KEY `uniq_session_line` (`session_id`,`product_id`,`variant_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(500) DEFAULT NULL,
  `meta_description` text,
  `serial` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `view_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_coupons` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `code` varchar(60) NOT NULL,
  `number_of_times` int unsigned NOT NULL DEFAULT '1',
  `used_count` int unsigned NOT NULL DEFAULT '0',
  `discount_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `applies_to` enum('all','product','category','subcategory') NOT NULL DEFAULT 'all',
  `product_id` int unsigned DEFAULT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `subcategory_id` int unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_subcategory_id` (`subcategory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_credit_payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `credit_id` int unsigned NOT NULL,
  `receipt_number` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(30) DEFAULT 'Cash',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_receipt_number` (`receipt_number`),
  KEY `idx_credit_id` (`credit_id`),
  CONSTRAINT `fk_credit_payment` FOREIGN KEY (`credit_id`) REFERENCES `ecom_credits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_credits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned DEFAULT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `promised_date` date DEFAULT NULL,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `customer_type` enum('online','offline') NOT NULL DEFAULT 'online',
  `address` text,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_gst_rates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_category_section_pins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_section_id` int NOT NULL,
  `product_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `category_section_id` (`category_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_category_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `sort_mode` enum('latest','best_selling','most_viewed','manual') NOT NULL DEFAULT 'latest',
  `product_limit` int NOT NULL DEFAULT '10',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_category_strip` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `sort_order` int DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_category_strip_v5` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int DEFAULT NULL,
  `custom_label` varchar(100) DEFAULT NULL,
  `custom_emoji` varchar(20) DEFAULT NULL,
  `custom_image` varchar(255) DEFAULT NULL,
  `custom_link` varchar(255) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_festive_banner` (
  `id` int NOT NULL DEFAULT '1',
  `image_desktop` varchar(255) DEFAULT NULL,
  `image_mobile` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT '#',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_icon_strip` (
  `id` int NOT NULL AUTO_INCREMENT,
  `emoji` varchar(10) DEFAULT '',
  `image` varchar(255) DEFAULT NULL,
  `label` varchar(60) NOT NULL,
  `link` varchar(255) DEFAULT '#',
  `color_class` varchar(10) DEFAULT 'c1',
  `sort_order` int DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_popular_pins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_section_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `custom_label` varchar(100) DEFAULT '',
  `custom_image` varchar(255) DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_section` (`section_id`),
  CONSTRAINT `fk_home_section_items_section` FOREIGN KEY (`section_id`) REFERENCES `ecom_home_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_type` enum('category_row','product_grid','festive_banner','manual_products') NOT NULL,
  `title` varchar(150) DEFAULT '',
  `category_id` int unsigned DEFAULT NULL,
  `source_type` enum('manual','category','latest') NOT NULL DEFAULT 'latest',
  `card_design` varchar(20) NOT NULL DEFAULT 'cmart',
  `product_limit` int DEFAULT '10',
  `banner_text` varchar(150) DEFAULT '',
  `banner_image` varchar(255) DEFAULT NULL,
  `dismissible` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_settings_v5` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_slider` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slide_type` varchar(20) NOT NULL DEFAULT 'content',
  `template` varchar(20) NOT NULL DEFAULT 'tpl-1',
  `badge_text` varchar(60) DEFAULT NULL,
  `heading` varchar(120) DEFAULT NULL,
  `subtext` varchar(200) DEFAULT NULL,
  `button_text` varchar(40) DEFAULT NULL,
  `icon_emoji` varchar(20) DEFAULT NULL,
  `icon_image` varchar(255) DEFAULT NULL,
  `image_desktop` varchar(255) DEFAULT NULL,
  `image_mobile` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT '#',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_slides` (
  `id` int NOT NULL AUTO_INCREMENT,
  `image` varchar(255) NOT NULL,
  `button_link` varchar(255) DEFAULT '#',
  `sort_order` int DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_trending_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_id` int NOT NULL,
  `item_type` enum('product','category','brand','manual') NOT NULL DEFAULT 'manual',
  `product_id` int DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `custom_label` varchar(150) DEFAULT NULL,
  `custom_image` varchar(255) DEFAULT NULL,
  `custom_link` varchar(255) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_trending_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL DEFAULT 'Featured',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_home_typography` (
  `setting_key` varchar(80) NOT NULL,
  `desktop_px` int NOT NULL,
  `mobile_px` int NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `variant_id` int unsigned DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `variant_label` varchar(150) DEFAULT NULL,
  `hsn_code` varchar(20) DEFAULT NULL,
  `qty` int unsigned NOT NULL DEFAULT '1',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gst_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `gst_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`order_id`) REFERENCES `ecom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_order_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `payment_method` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_order_payments_order` FOREIGN KEY (`order_id`) REFERENCES `ecom_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `is_guest` tinyint(1) NOT NULL DEFAULT '0',
  `customer_email` varchar(190) DEFAULT NULL,
  `shipping_address` text,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) DEFAULT NULL,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gst_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
  `payment_method` varchar(30) DEFAULT NULL,
  `order_type` enum('online','offline') NOT NULL DEFAULT 'online',
  `order_status` enum('Pending','In Progress','Delivered','Canceled') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_number` (`order_number`),
  KEY `idx_order_status` (`order_status`),
  KEY `idx_customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_payment_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `method_key` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `text` text,
  `config` text COMMENT 'JSON blob of gateway-specific fields (keys, merchant id, mode, etc.)',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_method_key` (`method_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_gallery_product` FOREIGN KEY (`product_id`) REFERENCES `ecom_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_reviews` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `rating` tinyint unsigned NOT NULL DEFAULT '5',
  `review_text` text,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`) REFERENCES `ecom_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_specs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `spec_key` varchar(100) NOT NULL,
  `spec_value` text NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tag_group` enum('badge','item_type') NOT NULL,
  `label` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT NULL COMMENT 'hex color for badge display, e.g. #ef4444',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_group_slug` (`tag_group`,`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_variants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `label` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(12,2) DEFAULT NULL,
  `stock_qty` int DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_product_sort` (`product_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_product_views` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `product_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_customer_viewed` (`customer_id`,`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `subcategory_id` int unsigned DEFAULT NULL,
  `brand_id` int unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(280) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `hsn_code` varchar(20) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `product_type` enum('physical','digital','license','affiliate') NOT NULL DEFAULT 'physical',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(12,2) DEFAULT NULL,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT '12.00',
  `stock_qty` int DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `description` text,
  `badge_tag` varchar(50) NOT NULL DEFAULT 'none',
  `item_type` varchar(50) NOT NULL DEFAULT 'normal',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_campaign` tinyint(1) NOT NULL DEFAULT '0',
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `campaign_price` decimal(12,2) DEFAULT NULL,
  `show_on_home` tinyint(1) NOT NULL DEFAULT '0',
  `download_link` varchar(500) DEFAULT NULL,
  `license_key` text,
  `affiliate_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `purchase_count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  UNIQUE KEY `uniq_barcode` (`barcode`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_brand_id` (`brand_id`),
  KEY `idx_status` (`status`),
  KEY `idx_is_campaign` (`is_campaign`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_subcategories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_category_id` (`category_id`),
  CONSTRAINT `fk_subcat_category` FOREIGN KEY (`category_id`) REFERENCES `ecom_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ecom_wishlist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_product` (`customer_id`,`product_id`),
  KEY `fk_wishlist_product` (`product_id`),
  CONSTRAINT `fk_wishlist_customer` FOREIGN KEY (`customer_id`) REFERENCES `ecom_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_product` FOREIGN KEY (`product_id`) REFERENCES `ecom_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `media` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsive_set` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `alt_text` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `caption` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `post_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  CONSTRAINT `media_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_chk_1` CHECK (json_valid(`responsive_set`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nws_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('newsletter','promotion') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'promotion',
  `status` enum('pending','sending','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `total_subscribers` int DEFAULT '0',
  `sent_count` int DEFAULT '0',
  `failed_count` int DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nws_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `subscriber_id` int NOT NULL,
  `attempts` tinyint DEFAULT '0',
  `last_attempt` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sub` (`campaign_id`,`subscriber_id`),
  KEY `idx_campaign_pending` (`campaign_id`,`attempts`),
  CONSTRAINT `nws_queue_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `nws_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nws_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_subscribed` tinyint(1) DEFAULT '1',
  `subscribed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_categories` (
  `post_id` int NOT NULL,
  `category_id` int NOT NULL,
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `post_categories_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_categories_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_meta` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `meta_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_value` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  CONSTRAINT `post_meta_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_stats_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `stat_date` date NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'direct',
  `country` varchar(2) NOT NULL DEFAULT 'XX',
  `views` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_date_source_country` (`post_id`,`stat_date`,`source`,`country`),
  KEY `idx_stat_date` (`stat_date`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `post_tag` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_post_tag` (`post_id`,`tag_id`),
  KEY `fk_tag_link` (`tag_id`),
  CONSTRAINT `fk_post_link` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tag_link` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_views` (
  `post_id` int NOT NULL,
  `views` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`post_id`),
  CONSTRAINT `post_views_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `faq_json` longtext COLLATE utf8mb4_unicode_ci,
  `last_date` date DEFAULT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  `author_id` int NOT NULL,
  `category_id` int NOT NULL,
  `state_id` int DEFAULT NULL,
  `slug` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `featured_image_id` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`),
  KEY `author_id` (`author_id`),
  KEY `featured_image_id` (`featured_image_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status_date` (`status`,`date`),
  KEY `idx_category_status_date` (`category_id`,`status`,`date`),
  CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`),
  CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `posts_ibfk_3` FOREIGN KEY (`featured_image_id`) REFERENCES `media` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `push_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int DEFAULT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_subscribers` int unsigned DEFAULT '0',
  `sent` int unsigned DEFAULT '0',
  `failed` int unsigned DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `post_id` (`post_id`),
  CONSTRAINT `push_campaigns_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `status` enum('pending','sent','failed','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_status` (`campaign_id`,`status`),
  KEY `idx_subscription` (`subscription_id`),
  CONSTRAINT `push_queue_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `push_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `push_queue_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subscription` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shop_customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `total_spent` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_shop_customers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shop_order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `product_name` varchar(180) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `fk_shop_items_order` (`order_id`),
  KEY `fk_shop_items_product` (`product_id`),
  CONSTRAINT `fk_shop_items_order` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_items_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shop_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(32) NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `shipping` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `fulfillment_status` enum('pending','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `shipping_address` text,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_shop_orders_customer` (`customer_id`),
  KEY `idx_shop_orders_status` (`fulfillment_status`),
  CONSTRAINT `fk_shop_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `shop_customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `shop_products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `description` text,
  `image_url` varchar(500) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `compare_price` decimal(12,2) DEFAULT NULL,
  `inventory` int NOT NULL DEFAULT '0',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `sku` (`sku`),
  KEY `idx_shop_products_category` (`category_id`),
  KEY `idx_shop_products_status` (`status`),
  CONSTRAINT `fk_shop_products_category` FOREIGN KEY (`category_id`) REFERENCES `shop_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `states` (
  `id` int NOT NULL AUTO_INCREMENT,
  `state_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `views` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `table_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `views` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tools_tracking` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tool_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tool_slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visits` int unsigned NOT NULL DEFAULT '0',
  `last_visited` datetime DEFAULT NULL,
  `star_1` int unsigned NOT NULL DEFAULT '0',
  `star_2` int unsigned NOT NULL DEFAULT '0',
  `star_3` int unsigned NOT NULL DEFAULT '0',
  `star_4` int unsigned NOT NULL DEFAULT '0',
  `star_5` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tool_slug_unique` (`tool_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','editor','author') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'author',
  `status` enum('active','pending','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `users_chk_1` CHECK (json_valid(`permissions`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visitor_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visit_date` date NOT NULL,
  `visitor_id` varchar(64) NOT NULL,
  `post_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_visit` (`visit_date`,`visitor_id`,`post_id`),
  KEY `idx_visit_date` (`visit_date`),
  KEY `idx_post_id` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `states` VALUES (1,'Andhra Pradesh','andhra-pradesh','All Andhra Pradesh Government Jobs, APPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(2,'Arunachal Pradesh','arunachal-pradesh','All Arunachal Pradesh Government Jobs, APPSC and all state entities body of exams. Check latest recruitment notifications.',34,'2026-05-10 13:45:04'),(3,'Assam','assam','All Assam Government Jobs, APSC and all state entities body of exams. Check latest recruitment notifications.',35,'2026-05-10 13:45:04'),(4,'Bihar','bihar','All Bihar Government Jobs, BPSC and all state entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(5,'Chhattisgarh','chhattisgarh','All Chhattisgarh Government Jobs, CGPSC and all state entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(6,'Goa','goa','All Goa Government Jobs, GPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(7,'Gujarat','gujarat','All Gujarat Government Jobs, GPSC and all state entities body of exams. Check latest recruitment notifications.',29,'2026-05-10 13:45:04'),(8,'Haryana','haryana','All Haryana Government Jobs, HPSC and all state entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(9,'Himachal Pradesh','himachal-pradesh','All Himachal Pradesh Government Jobs, HPPSC and all state entities body of exams. Check latest recruitment notifications.',32,'2026-05-10 13:45:04'),(10,'Jharkhand','jharkhand','All Jharkhand Government Jobs, JPSC and all state entities body of exams. Check latest recruitment notifications.',29,'2026-05-10 13:45:04'),(11,'Karnataka','karnataka','All Karnataka Government Jobs, KPSC and all state entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(12,'Kerala','kerala','All Kerala Government Jobs, KPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(13,'Madhya Pradesh','madhya-pradesh','All Madhya Pradesh Government Jobs, MPPSC and all state entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(14,'Maharashtra','maharashtra','All Maharashtra Government Jobs, MPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(15,'Manipur','manipur','All Manipur Government Jobs, MPSC and all state entities body of exams. Check latest recruitment notifications.',23,'2026-05-10 13:45:04'),(16,'Meghalaya','meghalaya','All Meghalaya Government Jobs, MPSC and all state entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(17,'Mizoram','mizoram','All Mizoram Government Jobs, MPSC and all state entities body of exams. Check latest recruitment notifications.',32,'2026-05-10 13:45:04'),(18,'Nagaland','nagaland','All Nagaland Government Jobs, NPSC and all state entities body of exams. Check latest recruitment notifications.',24,'2026-05-10 13:45:04'),(19,'Odisha','odisha','All Odisha Government Jobs, OPSC and all state entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(20,'Punjab','punjab','All Punjab Government Jobs, PPSC and all state entities body of exams. Check latest recruitment notifications.',32,'2026-05-10 13:45:04'),(21,'Rajasthan','rajasthan','All Rajasthan Government Jobs, RPSC, RSMSSB and all state entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(22,'Sikkim','sikkim','All Sikkim Government Jobs, SPPSC and all state entities body of exams. Check latest recruitment notifications.',32,'2026-05-10 13:45:04'),(23,'Tamil Nadu','tamil-nadu','All Tamil Nadu Government Jobs, TNPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(24,'Telangana','telangana','All Telangana Government Jobs, TSPSC and all state entities body of exams. Check latest recruitment notifications.',31,'2026-05-10 13:45:04'),(25,'Tripura','tripura','All Tripura Government Jobs, TPSC and all state entities body of exams. Check latest recruitment notifications.',36,'2026-05-10 13:45:04'),(26,'Uttar Pradesh','uttar-pradesh','All Uttar Pradesh Government Jobs, UPPSC, UPSSSC and all state entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(27,'Uttarakhand','uttarakhand','All Uttarakhand Government Jobs, UKPSC and all state entities body of exams. Check latest recruitment notifications.',35,'2026-05-10 13:45:04'),(28,'West Bengal','west-bengal','All West Bengal Government Jobs, WBPSC and all state entities body of exams. Check latest recruitment notifications.',34,'2026-05-10 13:45:04'),(29,'Andaman and Nicobar Islands','andaman-nicobar','All Andaman and Nicobar Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(30,'Chandigarh','chandigarh','All Chandigarh Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',32,'2026-05-10 13:45:04'),(31,'Dadra and Nagar Haveli and Daman and Diu','dadra-nagar-haveli-daman-diu','All Dadra and Nagar Haveli and Daman and Diu Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',21,'2026-05-10 13:45:04'),(32,'Delhi','delhi','All Delhi Government Jobs, DSSSB and all UT entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(33,'Jammu and Kashmir','jammu-kashmir','All Jammu and Kashmir Government Jobs, JKPSC and all UT entities body of exams. Check latest recruitment notifications.',30,'2026-05-10 13:45:04'),(34,'Ladakh','ladakh','All Ladakh Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(35,'Lakshadweep','lakshadweep','All Lakshadweep Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',33,'2026-05-10 13:45:04'),(36,'Puducherry','puducherry','All Puducherry Government Jobs and all UT entities body of exams. Check latest recruitment notifications.',35,'2026-05-10 13:45:04');

INSERT IGNORE INTO `ecom_gst_rates` VALUES (1,'GST Exempt / Nil-rated',0.00,0,'2026-09-05 05:53:36'),(2,'GST 5%',5.00,0,'2026-09-05 05:53:36'),(3,'GST 12%',12.00,1,'2026-09-05 05:53:36'),(4,'GST 18%',18.00,0,'2026-09-05 05:53:36'),(5,'GST 28%',28.00,0,'2026-09-05 05:53:36');

INSERT IGNORE INTO `ecom_payment_settings` VALUES (1,'cod','Cash On Delivery',NULL,'Cash on Delivery basically means you will pay the amount of product while you get the item delivered to you.',NULL,1,'2026-09-04 23:04:13'),(2,'paytm','Paytm',NULL,'Paytm is the faster & safer way to send money. Make an online payment via Paytm.',NULL,0,'2026-09-04 23:04:13'),(3,'phonepe','PhonePe',NULL,'PhonePe is the faster & safer way to send money. Make an online payment via PhonePe.',NULL,0,'2026-09-04 23:04:13'),(4,'razorpay','Razorpay',NULL,'Razorpay is the faster & safer way to send money. Make an online payment via Razorpay.',NULL,0,'2026-09-04 23:04:13'),(5,'bank_transfer','Bank Transfer',NULL,'Account Number: 000000000\nAccount Name: Your Business Name\nBank Name: Your Bank\nIFSC Code: XXXX0000000',NULL,0,'2026-09-04 23:04:13');

INSERT IGNORE INTO `ecom_business_settings` (`id`,`business_name`,`tagline`,`address`,`location`,`phone`,`contact_numbers`,`email`,`state`,`invoice_contact_numbers`,`social_media_json`) VALUES (1,'My Business','Your shop tagline','Shop address','City','','[]','','', '[]','[]');
SET FOREIGN_KEY_CHECKS = 1;
