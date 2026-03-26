# Loan Management System Summary

## What This System Is For

This system is for managing the full loan process of a lending business from application to payment collection.

It is built as a multi-tenant loan management platform, which means one system can support multiple lending branches or organizations while keeping their data separated.

## Main Purpose

The system is designed to help staff:

- register and manage customers
- receive and review loan applications
- approve or deny loans based on role
- assign loan officers
- release loan funds
- record and edit payments
- print receipts
- monitor loan status such as `PENDING`, `APPROVED`, `ACTIVE`, `OVERDUE`, and `CLOSED`
- generate reports and audit history
- manage staff accounts and tenant records

## How The System Is Structured

The project has two main parts that use the same database:

### 1. Staff Web Portal

Located under `/staff/`

This is for internal staff users such as:

- `SUPER_ADMIN`
- `ADMIN`
- `MANAGER`
- `CREDIT_INVESTIGATOR`
- `LOAN_OFFICER`
- `CASHIER`

The web portal is used for daily operations like loan review, approval, payment processing, reporting, and staff administration.

### 2. Mobile/API Side

Located under `/api/v1/`

This side is intended for customer-facing or mobile app use, including:

- customer registration and login
- loan application submission
- viewing loan status
- payment history access
- document uploads

## Core Business Flow

In practical terms, the system supports this process:

1. A customer is registered in the system.
2. A loan application is created.
3. A credit investigator reviews the application and requirements.
4. A manager or authorized approver approves or denies the loan.
5. A loan officer may be assigned.
6. Funds are released through the voucher/release flow.
7. Payments are recorded over time.
8. Staff track balances, overdue loans, and loan completion.
9. Reports and activity logs help management monitor operations.

## Key Features

- role-based access control
- multi-tenant data isolation
- customer and loan record management
- payment tracking and receipt printing
- requirement and document handling
- reporting and analytics
- audit trail through activity logs
- tenant and staff administration

## Short Plain-Language Description

This is a lending operations system for staff and customers. Staff use the web portal to process loans, approve applications, release funds, collect payments, and manage records. Customers are intended to interact through the API/mobile side for registration, applications, tracking, and document submission.
