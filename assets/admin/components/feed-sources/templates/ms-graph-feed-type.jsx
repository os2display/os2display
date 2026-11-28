import { useTranslation } from "react-i18next";
import { Alert } from "react-bootstrap";
import FormInput from "../../util/forms/form-input";

const MsGraphFeedType = ({ handleInput, formStateObject, mode }) => {
  const { t } = useTranslation("common", {
    keyPrefix: "ms-graph-feed-type",
  });

  return (
    <>
      <FormInput
        name="tenant_id"
        type="text"
        label={t("tenant-id")}
        formGroupClasses="mb-2"
        onChange={handleInput}
        placeholder={
          mode === "PUT" ? t("redacted-value-input-placeholder") : ""
        }
        value={formStateObject?.tenant_id}
      />

      <FormInput
        name="client_id"
        type="text"
        formGroupClasses="mb-2"
        label={t("client-id")}
        helpText={t("client-id-help")}
        onChange={handleInput}
        placeholder={
          mode === "PUT" ? t("redacted-value-input-placeholder") : ""
        }
        value={formStateObject?.client_id}
      />

      <FormInput
        name="client_secret"
        type="password"
        label={t("client-secret")}
        formGroupClasses="mb-2"
        onChange={handleInput}
        placeholder={
          mode === "PUT" ? t("redacted-value-input-placeholder") : ""
        }
        value={formStateObject?.client_secret}
      />

      <Alert className="mt-4" variant="info">
        {t("values-info")}
      </Alert>

      <FormInput
        name="group_id"
        type="text"
        label={t("group-id")}
        helpText={t("group-id-help")}
        formGroupClasses="mb-2"
        onChange={handleInput}
        value={formStateObject?.group_id ?? ""}
        required
      />
    </>
  );
};

export default MsGraphFeedType;
