# Hospital Management System - Relationship Map

## Core Relationships

### Users & Authentication
- **Users** table is the central authentication entity.
- **Patients** and **Staff** tables have a 1:1 relationship with **Users** via `user_id`.
  - `users.id` -> `patients.user_id`
  - `users.id` -> `staff.user_id`

### Clinical Flow
1. **Appointments**
   - Link **Patients** (`patient_id`) and **Staff** (Doctors) (`doctor_id`).
   - Can be assigned to a **Room** (`room_id`).
   - `appointments.id` is referenced by **Prescriptions** and **Billing**.

2. **Triage Analysis**
   - Linked directly to **Patients** (`patient_id`).
   - Stores AI analysis and doctor reviews.

3. **Prescriptions**
   - Linked to **Appointments** (`appointment_id`), **Patients** (`patient_id`), and **Staff** (`doctor_id`).
   - Contains JSONB data for medication details.

4. **Diagnostics (Labs & Radiology)**
   - **Laboratory Tests** and **Radiology Reports** link **Patients** and **Staff**.
   - Independent of specific appointments but tracked by time.

### Financial Flow
1. **Billing**
   - Linked to **Patients** (`patient_id`) and optionally **Appointments** (`appointment_id`).
   - Tracks total amount and payment status.

2. **Insurance Claims**
   - Linked to **Billing** (`billing_id`) and **Patients** (`patient_id`).
   - Tracks claim status with providers.

### Inventory & Resources
- **Pharmacy Inventory**: Standalone table for tracking medication stock.
- **Rooms**: Managed via `status` and linked to **Appointments**.
- **Ambulance**: Standalone tracking for fleet management.

### System & Support
- **Files**: Generic file storage linked to `uploader_id` (User) and polymorphic `related_entity_id`.
- **Audit Logs**: Tracks all actions by **Users** (`user_id`).
- **Notifications**: System alerts for **Users** (`user_id`).

## Foreign Key Summary

| Table | Foreign Key | References | Relationship |
|-------|-------------|------------|--------------|
| patients | user_id | users(id) | 1:1 |
| staff | user_id | users(id) | 1:1 |
| appointments | patient_id | patients(id) | N:1 |
| appointments | doctor_id | staff(id) | N:1 |
| appointments | room_id | rooms(id) | N:1 |
| triage_analysis | patient_id | patients(id) | N:1 |
| prescriptions | appointment_id | appointments(id) | N:1 |
| prescriptions | patient_id | patients(id) | N:1 |
| prescriptions | doctor_id | staff(id) | N:1 |
| laboratory_tests | patient_id | patients(id) | N:1 |
| laboratory_tests | doctor_id | staff(id) | N:1 |
| radiology_reports | patient_id | patients(id) | N:1 |
| radiology_reports | doctor_id | staff(id) | N:1 |
| billing | patient_id | patients(id) | N:1 |
| billing | appointment_id | appointments(id) | N:1 |
| insurance_claims | billing_id | billing(id) | N:1 |
| insurance_claims | patient_id | patients(id) | N:1 |
| files | uploader_id | users(id) | N:1 |
| audit_logs | user_id | users(id) | N:1 |
| notifications | user_id | users(id) | N:1 |
