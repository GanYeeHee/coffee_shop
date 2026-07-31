# Project Rules & Assignment Guidelines

This document defines the strict technology stack, coding constraints, database architecture, and assignment rules for the Coffee Shop E-Commerce system. All AI coding assistants MUST adhere strictly to these rules.

---

## 1. Web Technologies & Stack Requirements (Section 2.1)

* **PHP Engine**: PHP 8.2.12 or above. Must use native PHP (no frameworks).
* **Database Engine**: MySQL / MariaDB (InnoDB engine, `utf8mb4_unicode_ci`).
* **Database Access**: Must use **PDO (PHP Data Objects)** for ALL database operations and queries. 
* **Frontend Markup & Styling**: Custom **HTML5** and **CSS3**.
* **Client-Side Scripting**: **jQuery** is required instead of plain JavaScript whenever possible (e.g., use `$.ajax()`, `$('#id')`, `.on('click')`).

---

## 2. STRICT RESTRICTIONS & BANNED TOOLS (Section 2.2)

To avoid losing marks, the following are **STRICTLY PROHIBITED**:

* ❌ **NO CSS Frameworks**: Do NOT use Bootstrap, Tailwind CSS, Foundation, Bulma, etc. Write clean, custom CSS3.
* ❌ **NO PHP Frameworks**: Do NOT use Laravel, Symfony, CodeIgniter, Yii, CakePHP, etc.
* ❌ **NO JavaScript Frameworks**: Do NOT use React, Vue, Angular, Svelte, Alpine.js, etc.
* ❌ **NO Ready-Made Layout Templates**: Do NOT copy external HTML/CSS website templates. Write frontend layouts from scratch.
* ⚠️ **External Libraries Limit**: Do NOT import external PHP or JS libraries unless necessary. Custom hand-coded solutions receive higher marks.

---