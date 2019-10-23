"use strict";

// Source-only audit: this script never starts PHP, connects to MySQL, or makes HTTP requests.
const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "..");
const ignored = new Set([".git"]);
const phpFiles = [];

function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.name.startsWith(".") || ignored.has(entry.name)) continue;
    const filename = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(filename);
    else if (entry.name.endsWith(".php")) phpFiles.push(filename);
  }
}

function fail(message) {
  console.error("FAIL: " + message);
  process.exitCode = 1;
}

walk(root);
const postForms = [];
for (const filename of phpFiles) {
  const source = fs.readFileSync(filename, "utf8");
  const relative = path.relative(root, filename);
  for (const form of source.matchAll(/<form\b[\s\S]*?<\/form>/gi)) {
    if (!/method\s*=\s*["']post["']/i.test(form[0])) continue;
    postForms.push(relative);
    if (!/csrf_field\s*\(\s*\)/.test(form[0]))
      fail(relative + " has a POST form without csrf_field()");
  }
}

const schema = fs.readFileSync(path.join(root, "database/schema.sql"), "utf8");
for (const table of [
  "users",
  "discussion_groups",
  "group_members",
  "group_public_requests",
  "topics",
  "replies",
  "audit_logs",
  "auth_attempts",
  "notifications",
]) {
  if (!new RegExp("CREATE TABLE IF NOT EXISTS " + table + "\\b").test(schema))
    fail("schema is missing " + table);
}
if (!/visibility_status VARCHAR\(20\) NOT NULL DEFAULT 'private'/.test(schema))
  fail("groups are not private by default");
if (!/avatar_path VARCHAR\(255\) NULL/.test(schema))
  fail("users table is missing avatar_path");
if (!/DEFAULT CHARSET=utf8mb4/.test(schema)) fail("schema is not utf8mb4");

// The inbox has to support both reading and deleting a notification for its owner only.
const notificationSchema = schema.match(
  /CREATE TABLE IF NOT EXISTS notifications[\s\S]*?ENGINE=InnoDB/,
);
if (!notificationSchema) fail("schema is missing the notifications table body");
if (notificationSchema) {
  for (const column of ["read_at DATETIME NULL", "deleted_at DATETIME NULL"]) {
    if (!notificationSchema[0].includes(column))
      fail("notifications table is missing " + column);
  }
  if (
    !/CONSTRAINT fk_notifications_user FOREIGN KEY \(user_id\) REFERENCES users \(id\)/.test(
      notificationSchema[0],
    )
  )
    fail("notifications are not bound to a recipient");
}
const inboxSource = fs.readFileSync(path.join(root, "app/inbox.php"), "utf8");
for (const fn of [
  "function inbox_notify(",
  "function inbox_mark_read(",
  "function inbox_delete_item(",
  "function audit_notify(",
  "CREATE TABLE IF NOT EXISTS notifications",
]) {
  if (!inboxSource.includes(fn)) fail("app/inbox.php is missing " + fn);
}
const inboxRoute = fs.readFileSync(path.join(root, "inbox.php"), "utf8");
for (const action of [
  "inbox_read",
  "inbox_delete",
  "inbox_read_all",
  "inbox_delete_read",
]) {
  if (!inboxRoute.includes("'" + action + "'"))
    fail("inbox.php is missing " + action);
}
if (!/require_login\s*\(/.test(inboxRoute))
  fail("inbox.php does not require a logged-in reader");

const actionSource = fs.readFileSync(path.join(root, "actions.php"), "utf8");
for (const action of [
  "create_group",
  "join_group",
  "create_topic",
  "reply_topic",
  "delete_group",
]) {
  if (!actionSource.includes("'" + action + "'"))
    fail("actions.php is missing " + action);
}
const adminSource = fs.readFileSync(
  path.join(root, "admin_actions.php"),
  "utf8",
);
for (const action of [
  "review_public",
  "user_status",
  "restore_group",
  "purge_group",
  "purge_topic",
  "purge_reply",
  "reset_logs",
]) {
  if (!adminSource.includes("'" + action + "'"))
    fail("admin_actions.php is missing " + action);
}
if (!/is_site_owner\s*\(/.test(adminSource))
  fail("admin_actions.php no longer guards the site owner account");

if (process.exitCode) process.exit();
console.log("Static audit: OK");
console.log("PHP source files: " + phpFiles.length);
console.log("POST forms checked for CSRF: " + postForms.length);
console.log("Routes, schema tables, group actions, and admin actions: OK");
