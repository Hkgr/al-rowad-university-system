const fs = require("fs");
const path = require("path");

const root = process.cwd();
const brunoPath = path.join(root, "api", "bruno");

function walk(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      files.push(...walk(fullPath));
    } else if (entry.isFile() && entry.name.endsWith(".bru")) {
      files.push(fullPath);
    }
  }

  return files;
}

function detectMethod(content) {
  const match = content.match(/\n(post|put|patch|delete|get)\s*\{/i);
  return match ? match[1].toLowerCase() : null;
}

function getRequestBody(filePath, content, method) {
  const normalizedPath = filePath.replace(/\\/g, "/").toLowerCase();

  if (normalizedPath.includes("/auth/login.bru") || content.includes("url: {{base_url}}/login")) {
    return `body:json {
  {
    "email": "api.manager@alrowad.test",
    "password": "password123"
  }
}`;
  }

  if (content.includes("url: {{base_url}}/v1/students")) {
    return `body:json {
  {
    "student_number": "2026-0001",
    "admission_application_id": null,
    "first_name": "Ahmad",
    "last_name": "Ali",
    "father_name": "Mohammad",
    "mother_name": "Fatima",
    "date_of_birth": "2005-05-10",
    "gender": "male",
    "phone_number": "0999999999",
    "email": "student@example.com",
    "address": "Aleppo",
    "nationality": "Syrian",
    "academic_program_id": 1,
    "current_academic_level_id": 1,
    "enrollment_date": "2026-09-01",
    "student_status_id": 1
  }
}`;
  }

  if (content.includes("url: {{base_url}}/v1/registrations/register-student")) {
    return `body:json {
  {
    "student_id": 1,
    "course_offering_id": 1,
    "registered_by_user_id": 1,
    "advisor_user_id": null,
    "registration_date": "2026-09-01"
  }
}`;
  }

  return `body:json {
  {

  }
}`;
}

function ensureBodyMode(content, method) {
  if (!["post", "put", "patch"].includes(method)) {
    return content;
  }

  return content.replace(
    new RegExp(`${method}\\s*\\{([\\s\\S]*?)\\}`, "i"),
    (block) => {
      if (/body:\s*json/i.test(block)) {
        return block;
      }

      if (/body:\s*none/i.test(block)) {
        return block.replace(/body:\s*none/i, "body: json");
      }

      return block.replace(/\n\}/, "\n  body: json\n}");
    }
  );
}

function ensureContentType(content, method) {
  if (!["post", "put", "patch"].includes(method)) {
    return content;
  }

  if (/Content-Type:\s*application\/json/i.test(content)) {
    return content;
  }

  return content.replace(/headers\s*\{([\s\S]*?)\}/i, (full, inside) => {
    return `headers {${inside}
  Content-Type: application/json
}`;
  });
}

function ensureBodySection(filePath, content, method) {
  if (!["post", "put", "patch"].includes(method)) {
    return content;
  }

  if (/body:json\s*\{/i.test(content)) {
    return content;
  }

  const body = getRequestBody(filePath, content, method);
  return `${content.trim()}

${body}
`;
}

const files = walk(brunoPath);
let changed = 0;

for (const file of files) {
  let content = fs.readFileSync(file, "utf8");
  const method = detectMethod(content);

  if (!method) {
    continue;
  }

  const original = content;

  content = ensureBodyMode(content, method);
  content = ensureContentType(content, method);
  content = ensureBodySection(file, content, method);

  if (content !== original) {
    fs.writeFileSync(file, content, "utf8");
    changed++;
  }
}

console.log(`Updated ${changed} Bruno request files with JSON body support.`);
