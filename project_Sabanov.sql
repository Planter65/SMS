-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Дек 17 2025 г., 11:47
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
(5, 'IT отдел'),
(6, '<script>alert(\"fff\")</script>'),
(7, '<script>alert(\"fff\")</script>');

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
(18, 18, 5, 'sent', '2025-10-20 10:26:22'),
(19, 19, 4, 'sent', '2025-11-26 15:52:53'),
(20, 20, 4, 'Доставлено', '2025-12-11 10:00:44'),
(21, 21, 5, 'sent', '2025-12-11 10:11:32'),
(29, 28, 2, 'Ошибка', '2025-12-11 16:07:15'),
(30, 28, 3, 'Ошибка', '2025-12-11 16:07:15'),
(31, 28, 4, 'Ошибка', '2025-12-11 16:07:15'),
(32, 28, 5, 'Ошибка', '2025-12-11 16:07:16'),
(34, 28, 7, 'Ошибка', '2025-12-11 16:07:16'),
(42, 36, 5, 'Ошибка', '2025-12-15 11:26:23'),
(43, 37, 5, 'Доставлено', '2025-12-15 11:26:28'),
(44, 38, 3, 'Доставлено', '2025-12-17 10:46:10'),
(45, 39, 5, 'Доставлено', '2025-12-17 10:52:14');

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
(18, 'Rere'),
(19, 'Нужно в бухгалтерию отнести бумаги.'),
(20, 'Помоги'),
(21, 'Привет'),
(22, 'Ку-ку'),
(23, 'ку'),
(24, 'Привет'),
(25, 'Привет'),
(26, 'Привет'),
(27, 'Привет'),
(28, '123'),
(29, 'Нужен отчет по практике'),
(30, 'сюда'),
(31, 'куку'),
(32, 'Привет'),
(33, 'Привет'),
(34, 'Дорогой коллега. Нужно в 12:00 придти и помочь администратору с кодом.'),
(35, 'Завтра можешь не приходить.'),
(36, '12345'),
(37, '12345'),
(38, '123gdskgdsg'),
(39, 'zsiuthhdxrti');

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
(2, '70000000002', 'user', 1),
(3, '70000000004', 'Илья', 4),
(4, '70000000010', 'Дима', 1),
(5, '70000000011', 'Данил', 1),
(7, '+79505850017', 'Никита', 1);

-- --------------------------------------------------------

--
-- Структура таблицы `sms_settings`
--

CREATE TABLE `sms_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `sms_settings`
--

INSERT INTO `sms_settings` (`setting_key`, `setting_value`) VALUES
('SMSCRU_LOGIN', ''),
('SMSCRU_PASSWORD', ''),
('SMSRU_API_ID', '91E32E3C-A381-6FCE-B8B6-5752285D0AB5'),
('SMS_PROVIDER', 'emulation');

-- --------------------------------------------------------

--
-- Структура таблицы `companies`
--

CREATE TABLE `companies` (
  `CompanyID` int(11) NOT NULL,
  `CompanyName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `companies`
--

INSERT INTO `companies` (`CompanyID`, `CompanyName`) VALUES
(1, 'Шахта им. С.М. Кирова');

-- --------------------------------------------------------

--
-- Структура таблицы `sms_templates`
--

CREATE TABLE `sms_templates` (
  `TemplateID` int(11) NOT NULL,
  `CompanyID` int(11) NOT NULL,
  `TemplateName` varchar(255) NOT NULL,
  `TemplateText` text NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(82, 'user', 'sms_send', 'SMS: Rere; получатель ID: 5; SMS не отправлено: Ошибка отправки SMS: HTTP Error: 403', 'Илья', '::1', '2025-10-20 10:26:25'),
(83, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 6', 'Илья1', '::1', '2025-11-26 15:49:08'),
(84, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 6', 'Илья1', '::1', '2025-11-26 15:49:09'),
(85, 'user', 'sms_send', 'SMS: Нужно в бухгалтерию отнести бумаги.; получатель ID: 4', 'Илья1', '::1', '2025-11-26 15:52:53'),
(86, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 7', 'Илья1', '::1', '2025-12-06 22:53:24'),
(87, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 7', 'Илья1', '::1', '2025-12-06 22:53:25'),
(88, 'user', 'sms_send', 'SMS: Помоги; получатель ID: 4; SMS отправлено', 'Илья1', '::1', '2025-12-11 10:00:44'),
(89, 'user', 'sms_send', 'SMS: Привет; получатель ID: 5', 'Илья1', '::1', '2025-12-11 10:11:32'),
(90, 'users', 'update', 'Обновлен пользователь ID=11, имя: Данил, роль: user', 'Илья1', '::1', '2025-12-11 10:12:18'),
(91, 'users', 'update', 'Обновлен пользователь ID=12, имя: Илья1, роль: admin, телефон: +79505850016', 'Илья1', '::1', '2025-12-11 10:12:32'),
(92, 'user', 'sms_send', 'SMS: Ку-ку; получатель ID: 6', 'Илья1', '::1', '2025-12-11 10:12:45'),
(93, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 1, пропущено (без номера телефона): 4', 'Илья1', '::1', '2025-12-11 10:12:49'),
(94, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 4', 'Илья1', '::1', '2025-12-11 10:12:52'),
(95, 'user', 'sms_send', 'SMS: ку; получатель ID: 6; SMS отправлено', 'Илья1', '::1', '2025-12-11 13:36:55'),
(96, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 11', 'Илья1', '::1', '2025-12-11 13:36:57'),
(97, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 10', 'Илья1', '::1', '2025-12-11 13:36:58'),
(98, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 4', 'Илья1', '::1', '2025-12-11 13:37:56'),
(99, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 4', 'Илья1', '::1', '2025-12-11 13:47:39'),
(100, 'user', 'sms_send', 'SMS: Привет; получатель ID: 6; SMS не отправлено: Для работы с нашим сервисом, необходимо создать буквенного отправителя, соответствующего вашему сайту, названию юр. лица или товарному знаку - https://sms.ru/?panel=senders', 'Илья1', '::1', '2025-12-11 13:47:43'),
(101, 'user', 'sms_send', 'SMS: Привет; получатель ID: 6; SMS не отправлено: Для работы с нашим сервисом, необходимо создать буквенного отправителя, соответствующего вашему сайту, названию юр. лица или товарному знаку - https://sms.ru/?panel=senders', 'Илья1', '::1', '2025-12-11 13:47:45'),
(102, 'user', 'sms_send', 'SMS: Привет; получатель ID: 6; SMS не отправлено: Для работы с нашим сервисом, необходимо создать буквенного отправителя, соответствующего вашему сайту, названию юр. лица или товарному знаку - https://sms.ru/?panel=senders', 'Илья1', '::1', '2025-12-11 13:47:49'),
(103, 'user', 'sms_send', 'SMS: Привет; получатель ID: 6; SMS не отправлено: Для работы с нашим сервисом, необходимо создать буквенного отправителя, соответствующего вашему сайту, названию юр. лица или товарному знаку - https://sms.ru/?panel=senders', 'Илья1', '::1', '2025-12-11 13:47:52'),
(104, 'users', 'update', 'Обновлен пользователь ID=4, имя: Илья, роль: admin', 'Илья1', '::1', '2025-12-11 15:40:03'),
(105, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 3', 'Илья1', '::1', '2025-12-11 15:40:14'),
(106, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 3', 'Илья1', '::1', '2025-12-11 15:45:46'),
(107, 'users', 'delete', 'Удален пользователь ID=10', 'Илья1', '::1', '2025-12-11 15:49:27'),
(108, 'users', 'delete', 'Удален пользователь ID=4', 'Илья1', '::1', '2025-12-11 15:49:32'),
(109, 'users', 'add', 'Добавлен пользователь: Никита, роль: user, телефон: +79505850017', 'Илья1', '::1', '2025-12-11 15:52:31'),
(110, 'user', 'sync_users', 'Синхронизировано: 1 пользователей', 'Никита', '::1', '2025-12-11 15:52:52'),
(111, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 16:07:58'),
(112, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 16:07:59'),
(113, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 16:15:23'),
(114, 'user', 'sms_send', 'SMS: Нужен отчет по практике; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-11 16:48:09'),
(115, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 16:54:48'),
(116, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 16', 'Илья1', '::1', '2025-12-11 17:02:12'),
(117, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 16', 'Илья1', '::1', '2025-12-11 17:02:13'),
(118, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 16', 'Илья1', '::1', '2025-12-11 17:05:31'),
(119, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 16', 'Илья1', '::1', '2025-12-11 17:05:32'),
(120, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 17:07:49'),
(121, 'user', 'sms_send', 'SMS: сюда; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-11 17:52:23'),
(122, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-11 18:27:31'),
(123, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-12 08:09:54'),
(124, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 17', 'Илья1', '::1', '2025-12-12 08:10:01'),
(125, 'backup', 'create', 'Создан бекап backup_2025-12-12_02-08-31.json', 'Илья1', '::1', '2025-12-12 08:12:21'),
(126, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-12 09:23:51'),
(127, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-12 09:23:57'),
(128, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-12 09:57:08'),
(129, 'user', 'sms_send', 'SMS: куку; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-12 10:03:51'),
(130, 'user', 'sms_send', 'SMS: Привет; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-12 10:04:03'),
(131, 'user', 'sms_send', 'SMS: Привет; получателей: 1; отправлено: 1; ошибки: 0', 'Илья1', '::1', '2025-12-12 10:04:50'),
(132, 'user', 'sms_send', 'SMS: Дорогой коллега. Нужно в 12:00 придти и помочь администратору с кодом.; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-12 10:06:24'),
(133, 'user', 'read_status_update', 'Статус изменен на: read для сообщения ID: 21', 'Илья1', '::1', '2025-12-12 10:06:33'),
(134, 'users', 'sync', 'Синхронизация завершена! Добавлено: 0, обновлено: 0, пропущено (без номера телефона): 2', 'Илья1', '::1', '2025-12-12 11:07:17'),
(135, 'user', 'read_status_update', 'Статус изменен на: unread для сообщения ID: 21', 'Илья1', '::1', '2025-12-14 13:45:00'),
(136, 'user', 'sms_send', 'SMS: Завтра можешь не приходить.; получателей: 1; отправлено: 1; ошибки: 0', 'Илья1', '::1', '2025-12-15 08:25:25'),
(137, 'backup', 'create', 'Создан бекап backup_2025-12-15_03-19-31.json', 'Илья1', '::1', '2025-12-15 09:23:35'),
(138, 'user', 'sms_send', 'SMS: 12345; получателей: 1; отправлено: 0; ошибки: 1', 'Илья1', '::1', '2025-12-15 11:26:24'),
(139, 'user', 'sms_send', 'SMS: 12345; получателей: 1; отправлено: 1; ошибки: 0', 'Илья1', '::1', '2025-12-15 11:26:30'),
(140, 'users', 'update', 'Обновлен пользователь ID=12, имя: Илья, роль: admin, телефон: +79505850016', 'Илья1', '::1', '2025-12-16 09:58:01'),
(141, 'user', 'sms_send', 'SMS: 123gdskgdsg; получателей: 1; отправлено: 1; ошибки: 0', 'Илья', '::1', '2025-12-17 10:46:12'),
(142, 'user', 'sms_send', 'SMS: zsiuthhdxrti; получателей: 1; отправлено: 1; ошибки: 0', 'Илья', '::1', '2025-12-17 10:52:17'),
(143, 'groups', 'add', 'Добавлена группа: <script>alert(\"fff\")</script>', 'Илья', '::1', '2025-12-17 11:29:02'),
(144, 'groups', 'add', 'Добавлена группа: <script>alert(\"fff\")</script>', 'Илья', '::1', '2025-12-17 11:29:05');

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
(11, 'Данил', '', '$2y$10$T5GvKgZuMEPNpUWYgckoJO1/QdvRFCFP72qGqSK2BTTvtmFmJ4Vz6', 'user', NULL, 'active', '2025-10-19 16:21:47', 0, NULL, '2025-10-19 16:21:47', '2025-12-11 10:12:18'),
(12, 'Илья', '+79505850016', '$2y$10$4jUt4apTNGJeeycjO2QziedFNqb/ZLQ5jEpkLi7vAfF.FxxyWcxkm', 'admin', NULL, 'active', '2025-12-11 10:00:00', 0, '2025-12-12 09:23:11', '2025-11-24 19:34:00', '2025-12-16 09:58:00'),
(13, 'Никита', '+79505850017', '$2y$10$tmPoJ5SpXKYiq7PsQcStseQZiwFtxjJL4OlKQFQXNjy3AfossHyta', 'user', NULL, 'active', '2025-12-11 15:52:31', 0, NULL, '2025-12-11 15:52:31', '2025-12-11 15:52:31');

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
(6, 0, 18, 5, '2025-10-20 10:26:22', 'unread', NULL),
(7, 0, 19, 4, '2025-11-26 15:52:53', 'unread', NULL),
(8, 0, 20, 4, '2025-12-11 10:00:44', 'unread', NULL),
(9, 0, 21, 5, '2025-12-11 10:11:32', 'unread', NULL),
(10, 0, 22, 6, '2025-12-11 10:12:45', 'read', '2025-12-11 13:36:58'),
(11, 0, 23, 6, '2025-12-11 13:36:55', 'read', '2025-12-11 13:36:57'),
(12, 0, 24, 6, '2025-12-11 13:47:43', 'unread', NULL),
(13, 0, 25, 6, '2025-12-11 13:47:45', 'unread', NULL),
(14, 0, 26, 6, '2025-12-11 13:47:49', 'unread', NULL),
(15, 0, 27, 6, '2025-12-11 13:47:52', 'unread', NULL),
(16, 0, 29, 1, '2025-12-11 16:48:08', 'unread', NULL),
(17, 0, 30, 1, '2025-12-11 17:52:23', 'read', '2025-12-12 08:10:00'),
(18, 0, 31, 1, '2025-12-12 10:03:50', 'unread', NULL),
(19, 0, 32, 1, '2025-12-12 10:04:01', 'unread', NULL),
(20, 0, 33, 1, '2025-12-12 10:04:49', 'unread', NULL),
(21, 0, 34, 1, '2025-12-12 10:06:23', 'unread', NULL),
(22, 0, 35, 1, '2025-12-15 08:25:24', 'unread', NULL),
(23, 0, 36, 5, '2025-12-15 11:26:23', 'unread', NULL),
(24, 0, 37, 5, '2025-12-15 11:26:29', 'unread', NULL),
(25, 0, 38, 3, '2025-12-17 10:46:10', 'unread', NULL),
(26, 0, 39, 5, '2025-12-17 10:52:15', 'unread', NULL);

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
-- Индексы таблицы `sms_settings`
--
ALTER TABLE `sms_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Индексы таблицы `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`CompanyID`);

--
-- Индексы таблицы `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`TemplateID`),
  ADD KEY `CompanyID` (`CompanyID`);

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
  MODIFY `GroupID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `messagelogs`
--
ALTER TABLE `messagelogs`
  MODIFY `LogID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT для таблицы `messages`
--
ALTER TABLE `messages`
  MODIFY `MessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT для таблицы `recipients`
--
ALTER TABLE `recipients`
  MODIFY `RecipientID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `companies`
--
ALTER TABLE `companies`
  MODIFY `CompanyID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `sms_templates`
--
ALTER TABLE `sms_templates`
  MODIFY `TemplateID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `LogID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `UserID` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT для таблицы `user_feedback`
--
ALTER TABLE `user_feedback`
  MODIFY `FeedbackID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `user_messages`
--
ALTER TABLE `user_messages`
  MODIFY `UserMessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
-- Ограничения внешнего ключа таблицы `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD CONSTRAINT `sms_templates_ibfk_1` FOREIGN KEY (`CompanyID`) REFERENCES `companies` (`CompanyID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_group` FOREIGN KEY (`GroupID`) REFERENCES `groups` (`GroupID`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
