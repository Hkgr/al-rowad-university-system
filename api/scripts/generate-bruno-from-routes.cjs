const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const root = process.cwd();
const backendPath = path.join(root, "backend");
const apiPath = path.join(root, "api");
const reportsPath = path.join(apiPath, "reports");
const brunoPath = path.join(apiPath, "bruno");
const generatedPath = path.join(brunoPath, "All API");
const routesJsonPath = path.join(reportsPath, "laravel-api-routes.json");

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function writeFile(filePath, content) {
  ensureDir(path.dirname(filePath));
  fs.writeFileSync(filePath, content, "utf8");
}

function titleCase(value) {
  return value
    .replace(/[-_]+/g, " ")
    .split(" ")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function groupName(uriNoApi) {
  if (/^(login|logout|user)$/.test(uriNoApi)) {
    return "Auth";
  }

  const match = uriNoApi.match(/^v1\/([^/]+)/);
  if (match) {
    return titleCase(match[1]);
  }

  return "General";
}

function convertParams(uri) {
  const map = {
    student: "student_id",
    registration: "registration_id",
    course_offering: "course_offering_id",
    courseOffering: "course_offering_id",
    id: "id",
    user: "user_id",
  };

  return uri.replace(/\{([^}]+)\}/g, (_, param) => {
    const normalized = map[param] || param.replace(/[-.]/g, "_");
    return `{{${normalized}}}`;
  });
}

function safeFileName(method, uriNoApi, seq) {
  let name = `${seq} - ${method} ${uriNoApi}`;
  name = name.replace(/[\\/:*?"<>|{}]+/g, " ");
  name = name.replace(/\s+/g, " ").trim();

  if (name.length > 140) {
    name = name.slice(0, 140).trim();
  }

  return `${name}.bru`;
}

function requestName(method, uriNoApi) {
  let clean = uriNoApi
    .replace(/^v1\//, "")
    .replace(/\{([^}]+)\}/g, "by $1")
    .replace(/[-_/]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();

  if (!clean) clean = "root";

  return `${method} ${titleCase(clean)}`;
}

function bodyJson(method, uriNoApi) {
  if (uriNoApi === "login") {
    return `  {
    "email": "api.manager@alrowad.test",
    "password": "password123"
  }`;
  }

  if (method === "POST" && uriNoApi === "v1/students") {
    return `  {
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
  }`;
  }

  if (method === "POST" && uriNoApi === "v1/registrations/register-student") {
    return `  {
    "student_id": 1,
    "course_offering_id": 1,
    "registered_by_user_id": 1,
    "advisor_user_id": null,
    "registration_date": "2026-09-01"
  }`;
  }

  return `  {

  }`;
}

function bruContent(method, uriNoApi, urlPath, name, seq) {
  const methodLower = method.toLowerCase();
  const needsBody = ["POST", "PUT", "PATCH"].includes(method);
  const needsAuth = uriNoApi !== "login";

  const headers = [
    "  Accept: application/json",
    ...(needsBody ? ["  Content-Type: application/json"] : []),
    ...(needsAuth ? ["  Authorization: Bearer {{token}}"] : []),
  ].join("\n");

  if (needsBody) {
    return `meta {
  name: ${name}
  type: http
  seq: ${seq}
}

${methodLower} {
  url: {{base_url}}/${urlPath}
  body: json
  auth: none
}

headers {
${headers}
}

body:json {
${bodyJson(method, uriNoApi)}
}
`;
  }

  return `meta {
  name: ${name}
  type: http
  seq: ${seq}
}

${methodLower} {
  url: {{base_url}}/${urlPath}
  body: none
  auth: none
}

headers {
${headers}
}
`;
}

console.log("Generating Laravel API routes JSON...");

ensureDir(reportsPath);

const jsonOutput = execSync("php artisan route:list --path=api --json", {
  cwd: backendPath,
  encoding: "utf8",
});

writeFile(routesJsonPath, jsonOutput);

const routes = JSON.parse(jsonOutput);

if (!Array.isArray(routes) || routes.length === 0) {
  throw new Error("No API routes were found.");
}

if (fs.existsSync(generatedPath)) {
  fs.rmSync(generatedPath, { recursive: true, force: true });
}

ensureDir(generatedPath);

const seqByGroup = {};
let total = 0;

for (const route of routes) {
  let uri = String(route.uri || "").replace(/^\/+|\/+$/g, "");
  const methodText = String(route.method || "");

  if (!uri || !methodText) continue;
  if (!uri.startsWith("api")) continue;

  const uriNoApi = uri.replace(/^api\/?/, "").replace(/^\/+|\/+$/g, "");
  if (!uriNoApi) continue;

  const methods = methodText
    .split("|")
    .map((m) => m.trim().toUpperCase())
    .filter((m) => m && !["HEAD", "OPTIONS"].includes(m));

  for (const method of methods) {
    const group = groupName(uriNoApi);
    seqByGroup[group] = (seqByGroup[group] || 0) + 1;

    const seq = seqByGroup[group];
    const groupPath = path.join(generatedPath, group);
    const urlPath = convertParams(uriNoApi);
    const name = requestName(method, uriNoApi);
    const fileName = safeFileName(method, uriNoApi, seq);
    const filePath = path.join(groupPath, fileName);

    writeFile(filePath, bruContent(method, uriNoApi, urlPath, name, seq));
    total++;
  }
}

writeFile(
  path.join(generatedPath, "README.md"),
  `# Generated Bruno Requests

This folder is generated from Laravel routes.

Source command:

\`\`\`powershell
php artisan route:list --path=api --json
\`\`\`

Generated requests are intended as a full API inventory.

Important:
- These requests may need real IDs and seeded database records before they can run successfully.
- Curated feature requests remain in the main Bruno folders.
- Do not manually edit files inside \`All API\`; regenerate them instead.

Generated count: ${total}
`
);

console.log(`Generated ${total} Bruno requests into api/bruno/All API`);
