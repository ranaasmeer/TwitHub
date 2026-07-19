# 🐦 TwitHub

<p align="center">

![PHP](https://img.shields.io/badge/PHP-8+-777BB4?style=for-the-badge&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap)
![jQuery](https://img.shields.io/badge/jQuery-0769AD?style=for-the-badge&logo=jquery)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

</p>

> **Tweet. Reel. Connect. 🚀**

TwitHub is a **feature-rich Twitter/X-inspired social media platform** built using **PHP**, **MySQL**, and **Bootstrap**. It combines traditional micro-blogging with modern short-form video content, direct messaging, notifications, and an advanced administration panel.

Whether you're sharing thoughts, uploading reels, chatting with friends, or managing users as an administrator, TwitHub provides a complete social networking experience.

---

# ✨ Features

## 📝 Tweets

Share your thoughts instantly.

- Post tweets (280 characters)
- Upload up to **5 images**
- Upload up to **5 videos**
- Like tweets
- Comment on tweets
- Retweet posts
- Quote tweets
- Hashtag support
- User mentions
- Responsive tweet feed

---

## 🎬 Reels

Create and explore engaging short videos.

- Upload reels (up to 60 seconds)
- Background music library
- Mute original audio
- Vertical autoplay feed
- Like reels
- Save reels
- Share reels
- Modern reels interface

---

## 💬 Direct Messaging

Private communication between users.

Features include:

- Real-time messaging
- Send images
- Send videos
- Send documents
- Voice recording
- Voice messages
- Online user status
- Block / Unblock users

---

## 🔔 Notifications

Stay updated with platform activity.

Receive notifications for:

- Likes
- Comments
- Replies
- Retweets
- Mentions
- New followers
- Messages

---

## 👤 User Profiles

Every user has a customizable profile.

Features include:

- Profile picture
- Cover image
- Bio
- Followers
- Following
- Tweets
- Media
- Reels

---

## 🔍 Explore

Discover new content through:

- Trending hashtags
- Suggested users
- Popular tweets
- Viral reels

---

## ❤️ Social Features

Complete social networking functionality.

- Follow users
- Unfollow users
- Like posts
- Comment
- Retweet
- Quote Tweet
- Save reels
- Share content

---

## 🛡️ Admin Panel

A complete administration dashboard.

Administrator can:

- Dashboard analytics
- User management
- Tweet moderation
- Reel management
- Music library management
- Report handling
- Platform monitoring

---

## 📊 Dashboard & Analytics

Interactive dashboard displaying:

- Total Users
- Total Tweets
- Total Reels
- User Growth
- Platform Statistics
- Charts & Analytics

---

## 🔒 Security

TwitHub includes several security features.

- Password hashing using **bcrypt**
- PDO prepared statements
- Session-based authentication
- CSRF protection
- Secure login system
- Admin authorization
- User blocking
- Input validation
- SQL Injection protection

---

# 🛠 Tech Stack

| Technology | Purpose |
|------------|---------|
| **PHP 8.x** | Backend Development |
| **MySQL** | Database |
| **PDO** | Secure Database Layer |
| **Bootstrap 5** | Responsive UI |
| **jQuery** | AJAX & DOM Manipulation |
| **JavaScript** | Client-side Functionality |
| **HTML5** | Structure |
| **CSS3** | Styling |
| **Font Awesome** | Icons |
| **Chart.js** | Analytics Charts |
| **WaveSurfer.js** | Audio Visualization |
| **FFmpeg** | Video Processing |
| **PHPMailer** | Email Services |

---

# 📁 Project Structure

```
TwitHub/

├── admin/
├── ajax/
├── assets/
├── auth/
├── config/
├── includes/
├── messages/
├── reels/
├── uploads/
├── users/
├── vendor/
├── composer.json
└── index.php
```

---

# 📦 Installation

## Requirements

- PHP 8.x
- MySQL
- Apache Server
- Composer
- FFmpeg

---

## Clone Repository

```bash
git clone https://github.com/yourusername/TwitHub.git
```

---

## Move Project

Copy the project into:

```
xampp/htdocs/
```

---

## Create Database

Open **phpMyAdmin**

Create a database:

```
twithub
```

Import the provided SQL file.

---

## Configure Database

Update your database credentials inside:

```
config/database.php
```

Example:

```php
$host = "localhost";
$user = "root";
$password = "";
$database = "twithub";
```

---

## Install Composer Packages

```bash
composer install
```

---

## Start Server

Start:

- Apache
- MySQL

Then visit:

```
http://localhost/TwitHub
```

---

# 📸 Screenshots

## 🏠 Home

![Homepage](screenshots/Screenshot%20(940).png)

---

## 👤 Profile

![Profile](screenshots/Screenshot%20(941).png)

---

## 🔍 Explore

![Explore](screenshots/Screenshot%20(942).png)

---

## 🎬 Reels

![Reels](screenshots/Screenshot%20(944).png)

---

## 💬 Messages

![Messages](screenshots/Screenshot%20(946).png)

---

# 🚀 Upcoming Features

Future improvements include:

- Stories
- Video Calling
- Group Chats
- Live Streaming
- AI Content Suggestions
- Dark Mode
- Push Notifications
- Progressive Web App (PWA)
- Mobile Application
- Multi-language Support

---

# 👨‍💻 Author

**Sameer Ahmad**

GitHub: **@ranaasmeer**

Email: **ranaasmeer21@gmail.com**

---

# 🙏 Acknowledgments

Special thanks to my teachers and mentors for their continuous guidance and support throughout the development of this project.

Special inspiration from **Twitter/X** for the overall platform design and social networking experience.

---

# ⭐ Show Your Support

If you like this project, consider giving it a **⭐ Star** on GitHub.

Your support is greatly appreciated!