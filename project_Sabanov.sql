-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Ноя 24 2025 г., 19:34
-- Версия сервера: 10.6.22-MariaDB-0ubuntu0.22.04.1
-- Версия PHP: 8.1.2-1ubuntu2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `project_Sabanov`
--

DELIMITER $$
--
-- Процедуры
--
CREATE DEFINER=`Sabanov`@`%` PROCEDURE `sp_create_user` (IN `p_Username` VARCHAR(50), IN `p_PlainPassword` VARCHAR(255), IN `p_Role` ENUM('user','admin'))  BEGIN
    -- Проверка уникальности
    IF EXISTS (SELECT 1 FROM `users` WHERE `Username` = p_Username) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Пользователь уже существует';
    END IF;

    -- Минимальная безопасная заглушка хэширования (лучше хэшировать в PHP)
    INSERT INTO `users` (`Username`, `PasswordHash`, `Role`)
    VALUES (p_Username, UPPER(SHA2(p_PlainPassword, 256)), p_Role);
END$$

CREATE DEFINER=`Sabanov`@`%` PROCEDURE `sp_register_failed_login` (IN `p_Username` VARCHAR(50))  BEGIN
    UPDATE `users`
    SET `FailedLoginCount` = `FailedLoginCount` + 1,
        `LastFailedLoginAt` = NOW()
    WHERE `Username` = p_Username;
END$$

CREATE DEFINER=`Sabanov`@`%` PROCEDURE `sp_reset_failed_login` (IN `p_Username` VARCHAR(50))  BEGIN
    UPDATE `users`
    SET `FailedLoginCount` = 0
    WHERE `Username` = p_Username;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `groups`
--

CREATE TABLE `groups` (
  `GroupID` int(11) NOT NULL,
  `GroupName` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `groups`
--

INSERT INTO `groups` (`GroupID`, `GroupName`) VALUES
(1, 'Сотрудники'),
(2, 'Клиенты'),
(3, 'Поставщики'),
(4, 'Руководство'),
(5, 'IT отдел');

-- --------------------------------------------------------

--
-- Структура таблицы `messagelogs`
--

CREATE TABLE `messagelogs` (
  `LogID` int(11) NOT NULL,
  `MessageID` int(2) DEFAULT NULL,
  `RecipientID` int(2) DEFAULT NULL,
  `Status` varchar(20) NOT NULL,
  `SentDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `messagelogs`
--

INSERT INTO `messagelogs` (`LogID`, `MessageID`, `RecipientID`, `Status`, `SentDate`) VALUES
(1, 1, 1, 'sent', '2025-09-29 16:43:45'),
(2, 2, 1, 'sent', '2025-10-01 15:07:30'),
(6, 6, 3, 'sent', '2025-10-19 16:58:29'),
(7, 7, 5, 'sent', '2025-10-19 17:01:30'),
(8, 8, 5, 'sent', '2025-10-19 17:03:25'),
(9, 9, 3, 'sent', '2025-10-19 17:42:39'),
(10, 10, 3, 'sent', '2025-10-19 18:06:28'),
(11, 11, 5, 'sent', '2025-10-19 18:45:29'),
(12, 12, 5, 'sent', '2025-10-19 18:49:32'),
(13, 13, 5, 'sent', '2025-10-19 19:02:13'),
(14, 14, 5, 'sent', '2025-10-19 19:07:29'),
(15, 15, 3, 'sent', '2025-10-19 19:10:47'),
(16, 16, 5, 'sent', '2025-10-20 10:14:23'),
(17, 17, 5, 'sent', '2025-10-20 10:15:27'),
(18, 18, 5, 'sent', '2025-10-20 10:26:22');

-- --------------------------------------------------------

--
-- Структура таблицы `messages`
--

CREATE TABLE `messages` (
  `MessageID` int(11) NOT NULL,
  `Text` varchar(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `messages`
--

INSERT INTO `messages` (`MessageID`, `Text`) VALUES
(1, 'Сделай отчет'),
(2, 'Дима скинь работу'),
(6, 'Спасибо'),
(7, 'rere'),
(8, 'rere'),
(9, 'Спасибо'),
(10, 'Спасибо'),
(11, 'Привет'),
(12, 'КУ-ку'),
(13, 'епта'),
(14, 'Ghbdtncnde.'),
(15, 'ненен'),
(16, 'Куку'),
(17, 'Привет'),
(18, 'Rere');

-- --------------------------------------------------------

--
-- Структура таблицы `recipients`
--

CREATE TABLE `recipients` (
  `RecipientID` int(11) NOT NULL,
  `PhoneNumber` varchar(15) NOT NULL,
  `FullName` varchar(100) NOT NULL,
  `GroupID` int(2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `recipients`
--

INSERT INTO `recipients` (`RecipientID`, `PhoneNumber`, `FullName`, `GroupID`) VALUES
(1, '79505850016', 'Вареников', 5),
(2, '70000000002', 'user', 1),
(3, '70000000004', 'Илья', 4),
(4, '70000000010', 'Дима', 1),
(5, '70000000011', 'Данил', 1);

-- --------------------------------------------------------

--
-- Структура таблицы `system_logs`
--

CREATE TABLE `system_logs` (
  `LogID` int(11) NOT NULL,
  `Category` varchar(50) NOT NULL,
  `Action` varchar(50) NOT NULL,
  `Details` text DEFAULT NULL,
  `PerformedBy` varchar(100) DEFAULT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `system_logs`
--

INSERT INTO `system_logs` (`LogID`, `Category`, `Action`, `Details`, `PerformedBy`, `IPAddress`, `CreatedAt`) VALUES
(1, 'messages', 'send', 'Отправлено SMS: Дима скинь работу; получателей: 1', 'Илья', '::1', '2025-10-01 15:07:30'),
(2, 'backup', 'create', 'Создан бекап backup_2025-10-01_10-11-58.json', 'Илья', '::1', '2025-10-01 15:12:36'),
(3, 'users', 'add_error', 'Ошибка добавления пользователя: Данил', 'Илья', '::1', '2025-10-19 16:21:30'),
(4, 'users', 'add', 'Добавлен пользователь: Данил, роль: user', 'Илья', '::1', '2025-10-19 16:21:47'),
(5, 'user', 'message_error', 'Исключение: Cannot add or update a child row: a foreign key constraint fails (`project_Sabanov`.`messagelogs`, CONSTRAINT `messagelogs_ibfk_2` FOREIGN KEY (`RecipientID`) REFERENCES `recipients` (`RecipientID`) ON DELETE CASCADE ON UPDATE CASCADE)', 'Данил', '::1', '2025-10-19 16:54:24'),
(6, 'user', 'sms_error', 'Исключение: Cannot add or update a child row: a foreign key constraint fails (`project_Sabanov`.`messagelogs`, CONSTRAINT `messagelogs_ibfk_2` FOREIGN KEY (`RecipientID`) REFERENCES `recipients` (`RecipientID`) ON DELETE CASCADE ON UPDATE CASCADE)', 'Данил', '::1', '2025-10-19 16:55:40'),
(7, 'user', 'sms_error', 'Исключение: Cannot add or update a child row: a foreign key constraint fails (`project_Sabanov`.`messagelogs`, CONSTRAINT `messagelogs_ibfk_2` FOREIGN KEY (`RecipientID`) REFERENCES `recipients` (`RecipientID`) ON DELETE CASCADE ON UPDATE CASCADE)', 'Данил', '::1', '2025-10-19 16:58:17'),
(8, 'user', 'sync_users', 'Синхронизировано: 4 пользователей', 'Данил', '::1', '2025-10-19 16:58:19'),
(9, 'user', 'sms_send', 'SMS: Спасибо; получатель ID: 3', 'Данил', '::1', '2025-10-19 16:58:29'),
(10, 'user', 'sync_users', 'Синхронизировано: 0 пользователей', 'Данил', '::1', '2025-10-19 17:01:24'),
(11, 'user', 'sms_send', 'SMS: rere; получатель ID: 5', 'Данил', '::1', '2025-10-19 17:01:30'),
(12, 'user', 'sms_send', 'SMS: rere; получатель ID: 5', 'Данил', '::1', '2025-10-19 17:03:25'),
(13, 'user', 'sms_error', 'Исключение: Can\'t create table `project_Sabanov`.`user_messages` (errno: 150 \"Foreign key constraint is incorrectly formed\")', 'Данил', '::1', '2025-10-19 17:39:37'),
(14, 'user', 'sync_users', 'Синхронизировано: 0 пользователей', 'Данил', '::1', '2025-10-19 17:39:41'),
(15, 'user', 'sync_users', 'Синхронизировано: 0 пользователей', 'Данил', '::1', '2025-10-19 17:42:03'),
(16, 'user', 'sms_error', 'Исключение: Field \'UserMessageID\' doesn\'t have a default value', 'Данил', '::1', '2025-10-19 17:42:39'),
(17, 'user', 'sms_send', 'SMS: Спасибо; получатель ID: 3', 'Данил', '::1', '2025-10-19 18:06:28'),
(18, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:06:35'),
(19, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:06:36'),
(20, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:06:37'),
(21, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:03'),
(22, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:06'),
(23, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:07'),
(24, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:12'),
(25, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:13'),
(26, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:41:59'),
(27, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:42:00'),
(28, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:44:47'),
(29, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:44:55'),
(30, 'sms', 'send_ok', 'Сообщение ID=11 получателю ID=5', 'Илья', '::1', '2025-10-19 18:45:29'),
(31, 'sms', 'batch_send', 'Отправка завершена. Сообщение ID=11; всего: 1; ок: 1; ошибок: 0', 'Илья', '::1', '2025-10-19 18:45:29'),
(32, 'user', 'sync_users', 'Синхронизировано: 0 пользователей', 'Данил', '::1', '2025-10-19 18:49:04'),
(33, 'sms', 'send_ok', 'Сообщение ID=12 получателю ID=5', 'Илья', '::1', '2025-10-19 18:49:32'),
(34, 'sms', 'batch_send', 'Отправка завершена. Сообщение ID=12; всего: 1; ок: 1; ошибок: 0', 'Илья', '::1', '2025-10-19 18:49:32'),
(35, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:53:17'),
(36, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Данил', '::1', '2025-10-19 18:53:20'),
(37, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:01:35'),
(38, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:01:36'),
(39, 'sms', 'send_ok', 'Сообщение ID=13 получателю ID=5', 'Илья', '::1', '2025-10-19 19:02:13'),
(40, 'sms', 'batch_send', 'Отправка завершена. Сообщение ID=13; всего: 1; ок: 1; ошибок: 0', 'Илья', '::1', '2025-10-19 19:02:13'),
(41, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:07:13'),
(42, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:07:14'),
(43, 'user', 'sms_send', 'SMS: Ghbdtncnde.; получатель ID: 5', 'Илья', '::1', '2025-10-19 19:07:29'),
(44, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 2', 'Данил', '::1', '2025-10-19 19:10:22'),
(45, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 2', 'Данил', '::1', '2025-10-19 19:10:28'),
(46, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 2', 'Данил', '::1', '2025-10-19 19:10:29'),
(47, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 2', 'Данил', '::1', '2025-10-19 19:10:32'),
(48, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 2', 'Данил', '::1', '2025-10-19 19:10:33'),
(49, 'user', 'sms_send', 'SMS: ненен; получатель ID: 3', 'Данил', '::1', '2025-10-19 19:10:47'),
(50, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:12:50'),
(51, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:31'),
(52, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:32'),
(53, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:33'),
(54, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:34'),
(55, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:34'),
(56, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:35'),
(57, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:35'),
(58, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:37'),
(59, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:19:37'),
(60, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:19:38'),
(61, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:24:49'),
(62, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:24:50'),
(63, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 3', 'Илья', '::1', '2025-10-19 19:24:53'),
(64, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 1', 'Илья', '::1', '2025-10-19 19:24:55'),
(65, 'user', 'sync_users', 'Синхронизировано: 0 пользователей', 'Данил', '::1', '2025-10-19 19:57:57'),
(66, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 2', 'Данил', '::1', '2025-10-19 20:20:06'),
(67, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 2', 'Данил', '::1', '2025-10-19 20:20:07'),
(68, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 3', 'Илья', '::1', '2025-10-20 09:33:29'),
(69, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 3', 'Илья', '::1', '2025-10-20 09:33:35'),
(70, 'users', 'update', 'Обновлен пользователь ID=11, имя: Данил, роль: user, телефон: +79505850016', 'Илья', '::1', '2025-10-20 10:13:59'),
(71, 'users', 'update', 'Обновлен пользователь ID=11, имя: Данил, роль: user, телефон: +79505850016', 'Илья', '::1', '2025-10-20 10:14:02'),
(72, 'sms', 'send', 'SMS отправлено на номер: +79505850016, сообщение: Куку', 'Илья', '::1', '2025-10-20 10:14:24'),
(73, 'user', 'sms_send', 'SMS: Куку; получатель ID: 5; SMS отправлено', 'Илья', '::1', '2025-10-20 10:14:26'),
(74, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 4', 'Илья', '::1', '2025-10-20 10:14:44'),
(75, 'users', 'update', 'Обновлен пользователь ID=11, имя: Данил, роль: user, телефон: 79505850016', 'Илья', '::1', '2025-10-20 10:15:08'),
(76, 'sms', 'send', 'SMS отправлено на номер: +79505850016, сообщение: Привет', 'Илья', '::1', '2025-10-20 10:15:28'),
(77, 'user', 'sms_send', 'SMS: Привет; получатель ID: 5; SMS отправлено', 'Илья', '::1', '2025-10-20 10:15:29'),
(78, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 5', 'Илья', '::1', '2025-10-20 10:15:42'),
(79, 'users', 'update', 'Обновлен пользователь ID=11, имя: Данил, роль: user, телефон: +79505850016', 'Илья', '::1', '2025-10-20 10:26:09'),
(80, 'sms', 'send_attempt', 'Попытка отправки SMS на номер: +79505850016, сообщение: Rere', 'Илья', '::1', '2025-10-20 10:26:23'),
(81, 'sms', 'error', 'Исключение при отправке SMS: HTTP Error: 403', 'Илья', '::1', '2025-10-20 10:26:24'),
(82, 'user', 'sms_send', 'SMS: Rere; получатель ID: 5; SMS не отправлено: Ошибка отправки SMS: HTTP Error: 403', 'Илья', '::1', '2025-10-20 10:26:25');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `UserID` int(10) UNSIGNED NOT NULL,
  `Username` varchar(50) NOT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Role` enum('user','admin') NOT NULL DEFAULT 'user',
  `GroupID` int(2) DEFAULT NULL,
  `Status` enum('active','blocked') NOT NULL DEFAULT 'active',
  `PasswordCreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `FailedLoginCount` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `LastFailedLoginAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`UserID`, `Username`, `PhoneNumber`, `PasswordHash`, `Role`, `GroupID`, `Status`, `PasswordCreatedAt`, `FailedLoginCount`, `LastFailedLoginAt`, `CreatedAt`, `UpdatedAt`) VALUES
(2, 'user', NULL, '$2y$10$OMBKnLwQDY0GbdbQXnnI.eWi1XQ1gtfk5CCBsVdpvQy1Ho2RUpxzS', 'user', NULL, 'active', '2025-09-29 09:17:08', 0, '2025-10-01 14:47:50', '2025-09-29 09:17:08', '2025-10-01 14:48:53'),
(4, 'Илья', NULL, '$2y$10$vNk7cBpkZw/LlkZZ.gpAY.mgynZNiLhTUxlo/CS/lYg5BcJ/unIKS', 'admin', NULL, 'active', '2025-09-29 15:34:51', 5, '2025-11-24 19:24:15', '2025-09-29 15:34:51', '2025-11-24 19:24:15'),
(10, 'Дима', NULL, '$2y$10$sxaCaBTQARMRV8evcGgUSOOEqjRYia7NBK0.Cc6how0WN0Blj.eo6', 'user', NULL, 'active', '2025-09-29 16:27:35', 1, '2025-10-19 16:21:59', '2025-09-29 16:27:35', '2025-10-19 16:21:59'),
(11, 'Данил', '+79505850016', '$2y$10$T5GvKgZuMEPNpUWYgckoJO1/QdvRFCFP72qGqSK2BTTvtmFmJ4Vz6', 'user', NULL, 'active', '2025-10-19 16:21:47', 0, NULL, '2025-10-19 16:21:47', '2025-10-20 10:26:08');

-- --------------------------------------------------------

--
-- Структура таблицы `user_feedback`
--

CREATE TABLE `user_feedback` (
  `FeedbackID` int(11) NOT NULL,
  `Username` varchar(255) NOT NULL,
  `Status` varchar(50) NOT NULL,
  `Message` text NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `user_feedback`
--

INSERT INTO `user_feedback` (`FeedbackID`, `Username`, `Status`, `Message`, `CreatedAt`) VALUES
(1, 'user', 'Отказано', 'Потом сделаю', '2025-10-01 14:58:14');

-- --------------------------------------------------------

--
-- Структура таблицы `user_messages`
--

CREATE TABLE `user_messages` (
  `UserMessageID` int(11) NOT NULL,
  `SenderID` int(10) UNSIGNED NOT NULL,
  `MessageID` int(11) NOT NULL,
  `RecipientID` int(11) NOT NULL,
  `SentDate` datetime NOT NULL DEFAULT current_timestamp(),
  `ReadStatus` enum('unread','read') NOT NULL DEFAULT 'unread',
  `ReadDate` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `user_messages`
--

INSERT INTO `user_messages` (`UserMessageID`, `SenderID`, `MessageID`, `RecipientID`, `SentDate`, `ReadStatus`, `ReadDate`) VALUES
(1, 0, 10, 3, '2025-10-19 18:06:28', 'read', '2025-10-19 19:24:54'),
(2, 0, 14, 5, '2025-10-19 19:07:29', 'read', '2025-10-19 20:20:07'),
(3, 0, 15, 3, '2025-10-19 19:10:47', 'read', '2025-10-20 09:33:34'),
(4, 0, 16, 5, '2025-10-20 10:14:24', 'read', '2025-10-20 10:14:43'),
(5, 0, 17, 5, '2025-10-20 10:15:27', 'read', '2025-10-20 10:15:39'),
(6, 0, 18, 5, '2025-10-20 10:26:22', 'unread', NULL);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`GroupID`);

--
-- Индексы таблицы `messagelogs`
--
ALTER TABLE `messagelogs`
  ADD PRIMARY KEY (`LogID`),
  ADD KEY `MessageID` (`MessageID`),
  ADD KEY `RecipientID` (`RecipientID`);

--
-- Индексы таблицы `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`MessageID`);

--
-- Индексы таблицы `recipients`
--
ALTER TABLE `recipients`
  ADD PRIMARY KEY (`RecipientID`),
  ADD KEY `GroupID` (`GroupID`);

--
-- Индексы таблицы `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`LogID`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `ux_users_username` (`Username`),
  ADD KEY `fk_users_group` (`GroupID`);

--
-- Индексы таблицы `user_feedback`
--
ALTER TABLE `user_feedback`
  ADD PRIMARY KEY (`FeedbackID`);

--
-- Индексы таблицы `user_messages`
--
ALTER TABLE `user_messages`
  ADD PRIMARY KEY (`UserMessageID`),
  ADD KEY `SenderID` (`SenderID`),
  ADD KEY `MessageID` (`MessageID`),
  ADD KEY `RecipientID` (`RecipientID`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `groups`
--
ALTER TABLE `groups`
  MODIFY `GroupID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT для таблицы `messagelogs`
--
ALTER TABLE `messagelogs`
  MODIFY `LogID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT для таблицы `messages`
--
ALTER TABLE `messages`
  MODIFY `MessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT для таблицы `recipients`
--
ALTER TABLE `recipients`
  MODIFY `RecipientID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT для таблицы `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `LogID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `UserID` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT для таблицы `user_feedback`
--
ALTER TABLE `user_feedback`
  MODIFY `FeedbackID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `user_messages`
--
ALTER TABLE `user_messages`
  MODIFY `UserMessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `messagelogs`
--
ALTER TABLE `messagelogs`
  ADD CONSTRAINT `messagelogs_ibfk_1` FOREIGN KEY (`MessageID`) REFERENCES `messages` (`MessageID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `messagelogs_ibfk_2` FOREIGN KEY (`RecipientID`) REFERENCES `recipients` (`RecipientID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `recipients`
--
ALTER TABLE `recipients`
  ADD CONSTRAINT `recipients_ibfk_1` FOREIGN KEY (`GroupID`) REFERENCES `groups` (`GroupID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_group` FOREIGN KEY (`GroupID`) REFERENCES `groups` (`GroupID`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
