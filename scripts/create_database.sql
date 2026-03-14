-- Create database and tables
CREATE DATABASE IF NOT EXISTS warehouse;
USE warehouse;

-- Create items table
CREATE TABLE `items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `barcode` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `price` DECIMAL(10,2) DEFAULT NULL,
    `quantity` INT(11) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- Create shelves table
CREATE TABLE `shelves` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `location` VARCHAR(255) NOT NULL UNIQUE,
    `description` TEXT,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- Create inventory movements table
CREATE TABLE `inventory_movements` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `item_id` INT(11) NOT NULL,
    `shelf_id` INT(11) NOT NULL,
    `movement_type` ENUM('IN', 'OUT') NOT NULL,
    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    FOREIGN KEY (`shelf_id`) REFERENCES `shelves`(`id`)
) ENGINE=InnoDB;

-- Create stock levels table
CREATE TABLE `stock_levels` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `item_id` INT(11) NOT NULL,
    `shelf_id` INT(11) NOT NULL,
    `quantity` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE (`item_id`, `shelf_id`),
    FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    FOREIGN KEY (`shelf_id`) REFERENCES `shelves`(`id`)
) ENGINE=InnoDB;

-- Create archive_inventory_movements table
CREATE TABLE `archive_inventory_movements` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `item_id` INT(11) NOT NULL,
    `shelf_id` INT(11) NOT NULL,
    `movement_type` ENUM('IN', 'OUT') NOT NULL,
    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    FOREIGN KEY (`shelf_id`) REFERENCES `shelves`(`id`)
) ENGINE=InnoDB;
