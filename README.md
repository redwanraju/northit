# POS, Inventory & E-Commerce Management System

A complete **POS (Point of Sale)**, **Inventory**, and **E-Commerce Management System** built with PHP and MySQL. This system helps small and medium businesses manage products, suppliers, categories, stock, sales, online orders, and generate detailed reports — all from a single admin panel.

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-005C84?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-563D7C?style=for-the-badge&logo=bootstrap&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![AJAX](https://img.shields.io/badge/AJAX-0769AD?style=for-the-badge&logo=jquery&logoColor=white)

---

## ✨ Key Features

### 📦 Inventory Management
- **Product Management** — Add, edit, delete, and view products with images
- **Category Management** — Organize products by categories
- **Supplier Management** — Manage supplier information and contacts
- **Stock Tracking** — Real-time inventory updates with low-stock alerts
- **Purchase Records** — Track all purchase transactions

### 💰 POS (Point of Sale)
- **Quick Billing** — Create customer bills in seconds
- **Sales Tracking** — Record every sale with date and time
- **Payment Methods** — Cash, Card, Mobile Banking support
- **Receipt Printing** — Printable invoices for customers
- **Daily Sales Report** — View daily, weekly, and monthly sales

### 🛒 E-Commerce Features
- **Online Product Listing** — Display products on the website
- **Shopping Cart** — Add to cart with quantity management
- **Online Checkout** — Secure and simple checkout process
- **Customer Registration** — User signup and login system
- **Order Management** — Track and manage online orders
- **Referral System** — Customer referral engine for marketing
- **Customer Dashboard** — View order history and account details

### 🔧 Admin Panel
- **Secure Login** — Admin authentication system
- **Site Management** — Manage website content and settings
- **User Management** — View and manage registered users
- **Reports & Analytics** — Generate detailed business reports
- **Search & Filter** — Quickly find products, orders, or customers

### 📱 Responsive Design
- Works perfectly on **desktop, tablet, and mobile**
- Mobile-optimized admin panel and checkout

---

## 🛠️ Tech Stack

| Technology | Purpose |
|---|---|
| **PHP** | Backend logic |
| **MySQL** | Database |
| **MySQLi** | Database connection |
| **AJAX** | Dynamic content loading without page reload |
| **Bootstrap** | Responsive UI framework |
| **JavaScript** | Client-side interactivity |
| **HTML5 & CSS3** | Structure and styling |
| **jQuery** | DOM manipulation and AJAX requests |

---

## 📁 Project Structure
northit/
│
├── 📄 index.php # Dashboard / Homepage
├── 📄 connect.php # Database connection
├── 📄 login.php # Admin login
├── 📄 register.php # User registration
├── 📄 logout.php # Logout
│
├── 🛍️ PRODUCT MANAGEMENT
│ ├── addproduct.php # Add new product
│ ├── processproduct.php # Process product data
│ ├── deleteproduct.php # Delete product
│ └── createcategory.php # Add category
│
├── 🏷️ CATEGORY & SUPPLIER
│ ├── createcategory.php # Add category
│ ├── deletecategory.php # Delete category
│ ├── createsupplier.php # Add supplier
│ └── deletesupplier.php # Delete supplier
│
├── 💰 POS / SALES
│ ├── createlbs.php # Create bill/label
│ ├── deletelbs.php # Delete bill/label
│ ├── online_checkout.php # Online checkout
│ └── online_business_report.php # Business reports
│
├── 🛒 E-COMMERCE
│ ├── online_customers.php # Customer management
│ ├── order_management.php # Order management
│ ├── registered_users.php # Registered users list
│ ├── customer_dashboard_banner_click.php
│ ├── customer_dashboard_banner_widget.php
│ └── customer_dashboard_banners.php
│
├── 🔗 REFERRAL SYSTEM
│ ├── referral_engine.php # Referral logic
│ └── referral_management.php # Referral management
│
├── 🔐 SECURITY & ADMIN
│ ├── site_admin_guard.php # Admin access control
│ └── site_management.php # Site settings
│
├── 📝 BLOG / POSTS
│ ├── createpost.php # Create post
│ └── deletepost.php # Delete post
│
├── 🎨 ASSETS
│ ├── mobile_admin_app.css # Mobile admin CSS
│ ├── mobile_admin_app.js # Mobile admin JS
│ └── mobile_checkout_app.css # Mobile checkout CSS
│
├── 🗄️ DATABASE
│ └── demodatabase.sql # Full database schema
│
└── 📖 README.md # Project documentation

text

---

## ⚙️ Installation Guide

Follow these steps to run the project locally:

### 1. Clone the Repository
```bash
git clone https://github.com/redwanraju/northit.git
2. Move to Your Server Directory
Place the folder inside:

htdocs (for XAMPP)

www (for WAMP)

var/www/html (for LAMP)

3. Import the Database
Open phpMyAdmin

Create a new database named northit

Click Import and select demodatabase.sql

Click Go

4. Configure Database Connection
Open connect.php and update your credentials:

php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "northit";
5. Run the Project
Open your browser and go to:

text
http://localhost/northit/
6. Admin Login
URL: http://localhost/northit/login.php

Use the credentials from the database (check demodatabase.sql)

📸 Screenshots
Screenshots will be added soon.

Planned Screenshots:
🖥️ Admin Dashboard

📦 Product Management Page

💰 POS Billing Screen

🛒 E-Commerce Storefront

📊 Reports & Analytics

🚀 Live Demo
Live demo will be available soon.
Planned hosting: InfinityFree (free PHP hosting)

🎯 Use Cases
This system is perfect for:

🏪 Grocery & Super shops

📦 Wholesale businesses

🔧 Hardware stores

🏢 Small and medium enterprises

📚 School / Library inventory

🏥 Pharmacy stock management

🛍️ Online shops and e-commerce stores

🔮 Future Improvements
□ Barcode scanning support
□ SMS notifications for orders
□ Multi-user roles (Admin, Manager, Cashier)
□ REST API for mobile apps
□ Online backup feature
□ Multi-language support (Bangla / English)
□ Payment gateway integration (bKash, Nagad, Stripe)
□ Advanced analytics dashboard
□ Export reports to PDF / Excel
🤝 Contributing
Contributions, issues, and feature requests are welcome!

Fork the project

Create your feature branch (git checkout -b feature/NewFeature)

Commit your changes (git commit -m 'Add NewFeature')

Push to the branch (git push origin feature/NewFeature)

Open a Pull Request

📄 License
This project is open-source and available for learning and personal use.

For commercial use, please contact the developer.

👨‍💻 About the Developer
Redwan Raju — PHP Web Developer

I specialize in fixing PHP website bugs, building custom admin panels, and developing POS & E-commerce systems.

🌐 Portfolio: redwanraju.github.io

💼 LinkedIn: redwan-raju

📧 Email: redwanraju75@gmail.com

🐙 GitHub: @redwanraju

🎨 Fiverr: redwanraju257

⭐ If you find this project useful, please give it a star! ⭐

