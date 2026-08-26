# Social W3Lead API Documentation (v1)

Base Production API URL: `https://social-api.w3lead.in/api/v1`
Local Development API URL: `http://localhost:8000/api/v1`

---

## 🔑 Authentication

### Register Account
`POST /auth/register`
**Body**:
```json
{
  "name": "Alex Smith",
  "email": "alex@w3lead.in",
  "password": "password123",
  "workspace_name": "Acme Marketing",
  "timezone": "America/New_York"
}
```
**Response (201)**:
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "user": { "id": 1, "name": "Alex Smith", "email": "alex@w3lead.in" },
    "token": "1|sanctum_plain_text_token...",
    "current_workspace": { "id": 1, "name": "Acme Marketing", "slug": "acme-marketing" }
  }
}
```

### Login
`POST /auth/login`
**Body**:
```json
{
  "email": "alex@w3lead.in",
  "password": "password123"
}
```

### Get Authenticated User & Workspace
`GET /auth/me`
**Headers**: `Authorization: Bearer <TOKEN>`

---

## 🏢 Workspaces

### List Workspaces
`GET /workspaces`

### Create Workspace
`POST /workspaces`

### Add Team Member
`POST /workspaces/{workspace_id}/members`
**Body**:
```json
{
  "email": "member@w3lead.in",
  "role": "editor"
}
```

---

## 📝 Posts

### List Posts
`GET /posts?status=scheduled&platform=linkedin&page=1`
**Headers**: `X-Workspace-Id: 1`

### Create Post
`POST /posts`
**Headers**: `X-Workspace-Id: 1`
**Body**:
```json
{
  "title": "Product Launch Announcement",
  "content": "Excited to introduce our new feature set!",
  "is_scheduled": true,
  "scheduled_at": "2026-09-01T14:00:00Z",
  "social_account_ids": [1, 2],
  "variants": [
    {
      "platform": "linkedin",
      "content": "Excited to introduce our new feature set for enterprise teams! #Growth"
    }
  ]
}
```

---

## 🤖 AI Content Generation

### Generate Post Draft
`POST /ai/generate`
**Body**:
```json
{
  "prompt": "Write a 2-sentence announcement about our AI scheduling feature."
}
```

---

## 📊 Analytics & Usage

### Get Analytics Summary
`GET /analytics?days=30`

### Get Monthly Subscription Quota Usage
`GET /subscription/usage`
