import { Users } from "lucide-react";
import { registerModule, registerFeature } from "@pageflow/admin";

registerFeature({
  id: "user",
  label: "User management",
});

registerModule({
  id: "user",
  sectionLabel: "Users",
  order: 40,
  features: ["user"],
  items: [
    {
      id: "users",
      label: "Users",
      icon: Users,
      path: "/admin/users",
    },
  ],
});
