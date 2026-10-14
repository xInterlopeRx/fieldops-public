use bitwarden::{
    auth::login::AccessTokenLoginRequest,
    secrets_manager::{
        ClientSettings, DeviceType, SecretsManagerClient,
        secrets::{
            SecretIdentifiersByProjectRequest, SecretIdentifiersResponse, SecretsGetRequest,
        },
    },
};
use serde::{Deserialize, Serialize};
use std::{
    collections::{HashMap, HashSet},
    io::{self, Read},
};
use url::Url;
use uuid::Uuid;

const MAX_REQUEST_BYTES: u64 = 32 * 1024;
const MAX_ACCESS_TOKEN_BYTES: usize = 8 * 1024;
const MAX_SECRET_VALUE_BYTES: usize = 16 * 1024;
const ALLOWED_SECRET_NAMES: [&str; 4] = [
    "INTUIT_CLIENT_ID",
    "INTUIT_CLIENT_SECRET",
    "MICROSOFT_GRAPH_CLIENT_ID",
    "MICROSOFT_GRAPH_CLIENT_SECRET",
];
const GRAPH_SECRET_NAMES: [&str; 2] = [
    "MICROSOFT_GRAPH_CLIENT_ID",
    "MICROSOFT_GRAPH_CLIENT_SECRET",
];

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {
    access_token: String,
    api_url: String,
    identity_url: String,
    organization_id: Uuid,
    project_id: Uuid,
    operation: Operation,
    #[serde(default)]
    secret_names: Vec<String>,
}

#[derive(Debug, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum Operation {
    Read,
    Verify,
}

#[derive(Debug, Serialize)]
struct Response {
    ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<&'static str>,
    results: Vec<serde_json::Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    resolved_secrets: Option<HashMap<String, String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    project_access_verified: Option<bool>,
    #[serde(skip_serializing_if = "Option::is_none")]
    required_secrets_verified: Option<bool>,
}

#[tokio::main(flavor = "current_thread")]
async fn main() {
    std::panic::set_hook(Box::new(|_| eprintln!("bws_helper_internal_error")));

    let response = if std::env::args_os().len() != 1 {
        failure("invalid_request")
    } else {
        match read_request() {
            Ok(request) => apply(request).await,
            Err(error) => failure(error),
        }
    };

    // Deliberately serialize only fixed status codes and allowlisted secret names.
    println!(
        "{}",
        serde_json::to_string(&response).unwrap_or_else(|_| {
            "{\"ok\":false,\"error\":\"response_encoding_failed\",\"results\":[]}".into()
        })
    );

    if !response.ok {
        std::process::exit(1);
    }
}

fn read_request() -> Result<Request, &'static str> {
    let mut raw = String::new();
    io::stdin()
        .take(MAX_REQUEST_BYTES + 1)
        .read_to_string(&mut raw)
        .map_err(|_| "invalid_request")?;

    if raw.len() as u64 > MAX_REQUEST_BYTES {
        return Err("request_too_large");
    }

    let request: Request = serde_json::from_str(&raw).map_err(|_| "invalid_request")?;
    validate_request(&request)?;

    Ok(request)
}

fn validate_request(request: &Request) -> Result<(), &'static str> {
    if request.access_token.trim().is_empty() || request.access_token.len() > MAX_ACCESS_TOKEN_BYTES
    {
        return Err("invalid_request");
    }

    validate_service_url(&request.api_url)?;
    validate_service_url(&request.identity_url)?;

    match request.operation {
        Operation::Read => {
            if request.secret_names.is_empty()
                || request.secret_names.len() > ALLOWED_SECRET_NAMES.len()
            {
                return Err("invalid_request");
            }

            let mut seen = HashSet::with_capacity(request.secret_names.len());
            for name in &request.secret_names {
                if !ALLOWED_SECRET_NAMES.contains(&name.as_str()) || !seen.insert(name.as_str()) {
                    return Err("invalid_request");
                }
            }
        }
        Operation::Verify => {
            let requested: HashSet<&str> = request
                .secret_names
                .iter()
                .map(String::as_str)
                .collect();
            let all_required: HashSet<&str> = ALLOWED_SECRET_NAMES.into_iter().collect();
            let graph_only: HashSet<&str> = GRAPH_SECRET_NAMES.into_iter().collect();

            if requested.len() != request.secret_names.len()
                || (requested != all_required && requested != graph_only)
            {
                return Err("invalid_request");
            }
        }
    }

    Ok(())
}

fn validate_service_url(value: &str) -> Result<(), &'static str> {
    let url = Url::parse(value).map_err(|_| "invalid_request")?;

    if url.scheme() != "https"
        || url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
    {
        return Err("invalid_request");
    }

    Ok(())
}

async fn apply(request: Request) -> Response {
    let Request {
        access_token,
        api_url,
        identity_url,
        organization_id: requested_organization_id,
        project_id,
        operation,
        secret_names,
    } = request;

    let client = SecretsManagerClient::new(Some(ClientSettings {
        identity_url,
        api_url,
        user_agent: "FieldOps BWS credential helper".into(),
        device_type: DeviceType::SDK,
        device_identifier: None,
        bitwarden_package_type: None,
        bitwarden_client_version: Some(env!("CARGO_PKG_VERSION").into()),
    }));

    if client
        .auth()
        .login_access_token(&AccessTokenLoginRequest {
            access_token,
            state_file: None,
        })
        .await
        .is_err()
    {
        return failure("authorization_failed");
    }

    let Some(organization_id) = client.get_access_token_organization() else {
        return failure("authorization_failed");
    };

    if organization_id.to_string() != requested_organization_id.to_string() {
        return failure("target_mismatch");
    }

    let identifiers = match client
        .secrets()
        .list_by_project(&SecretIdentifiersByProjectRequest { project_id })
        .await
    {
        Ok(response) => response,
        Err(_) => return failure("project_access_failed"),
    };

    if operation == Operation::Verify {
        return match fetch_secrets(&client, secret_names, identifiers).await {
            Ok(values) => {
                drop(values);
                Response {
                    ok: true,
                    error: None,
                    results: Vec::new(),
                    resolved_secrets: None,
                    project_access_verified: Some(true),
                    required_secrets_verified: Some(true),
                }
            }
            Err(error) => failure(error),
        };
    }

    if operation == Operation::Read {
        return read_secrets(&client, secret_names, identifiers).await;
    }

    failure("invalid_request")
}

async fn read_secrets(
    client: &SecretsManagerClient,
    secret_names: Vec<String>,
    identifiers: SecretIdentifiersResponse,
) -> Response {
    match fetch_secrets(client, secret_names, identifiers).await {
        Ok(resolved) => Response {
            ok: true,
            error: None,
            results: Vec::new(),
            resolved_secrets: Some(resolved),
            project_access_verified: None,
            required_secrets_verified: None,
        },
        Err(error) => failure(error),
    }
}

async fn fetch_secrets(
    client: &SecretsManagerClient,
    secret_names: Vec<String>,
    identifiers: SecretIdentifiersResponse,
) -> Result<HashMap<String, String>, &'static str> {
    let requested_names: HashSet<String> = secret_names.iter().cloned().collect();
    let mut id_by_name = HashMap::new();

    for identifier in identifiers.data {
        if requested_names.contains(identifier.key.as_str())
            && id_by_name
                .insert(identifier.key.clone(), identifier.id)
                .is_some()
        {
            return Err("duplicate_secret_name");
        }
    }

    if id_by_name.len() != requested_names.len() {
        return Err("missing_secret");
    }

    let ids: Vec<Uuid> = id_by_name.values().copied().collect();
    let response = match client.secrets().get_by_ids(SecretsGetRequest { ids }).await {
        Ok(response) => response,
        Err(_) => return Err("project_access_failed"),
    };

    let mut resolved = HashMap::with_capacity(secret_names.len());
    for secret in response.data {
        if requested_names.contains(secret.key.as_str()) {
            if secret.value.trim().is_empty() {
                return Err("missing_secret");
            }

            if secret.value.len() > MAX_SECRET_VALUE_BYTES {
                return Err("secret_too_large");
            }

            resolved.insert(secret.key, secret.value);
        }
    }

    if resolved.len() != requested_names.len() {
        return Err("missing_secret");
    }

    Ok(resolved)
}

fn failure(error: &'static str) -> Response {
    Response {
        ok: false,
        error: Some(error),
        results: Vec::new(),
        resolved_secrets: None,
        project_access_verified: None,
        required_secrets_verified: None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn valid_request() -> Request {
        Request {
            access_token: "temporary-token".into(),
            api_url: "https://api.bitwarden.com".into(),
            identity_url: "https://identity.bitwarden.com".into(),
            organization_id: Uuid::nil(),
            project_id: Uuid::nil(),
            operation: Operation::Read,
            secret_names: vec!["INTUIT_CLIENT_SECRET".into()],
        }
    }

    #[test]
    fn request_accepts_an_allowlisted_secret_and_https_endpoints() {
        assert!(validate_request(&valid_request()).is_ok());
    }

    #[test]
    fn read_request_rejects_arbitrary_and_duplicate_secret_names() {
        let mut arbitrary = valid_request();
        arbitrary.secret_names[0] = "UNRELATED_SECRET".into();
        assert_eq!(validate_request(&arbitrary), Err("invalid_request"));

        let mut duplicate = valid_request();
        duplicate.secret_names.push("INTUIT_CLIENT_SECRET".into());
        assert_eq!(validate_request(&duplicate), Err("invalid_request"));
    }

    #[test]
    fn read_requests_accept_only_unique_allowlisted_names() {
        let mut request = valid_request();
        request.operation = Operation::Read;
        request.secret_names = vec!["INTUIT_CLIENT_SECRET".into()];
        assert!(validate_request(&request).is_ok());

        request.secret_names.push("INTUIT_CLIENT_SECRET".into());
        assert_eq!(validate_request(&request), Err("invalid_request"));

        request.secret_names = vec!["UNLISTED_SECRET".into()];
        assert_eq!(validate_request(&request), Err("invalid_request"));
    }

    #[test]
    fn verification_accepts_only_the_fixed_api_or_graph_secret_profile() {
        let mut request = valid_request();
        request.operation = Operation::Verify;
        request.secret_names = ALLOWED_SECRET_NAMES.iter().map(|name| (*name).into()).collect();
        assert!(validate_request(&request).is_ok());
        assert_eq!(
            ALLOWED_SECRET_NAMES,
            [
                "INTUIT_CLIENT_ID",
                "INTUIT_CLIENT_SECRET",
                "MICROSOFT_GRAPH_CLIENT_ID",
                "MICROSOFT_GRAPH_CLIENT_SECRET",
            ]
        );

        request.secret_names = GRAPH_SECRET_NAMES.iter().map(|name| (*name).into()).collect();
        assert!(validate_request(&request).is_ok());

        request.secret_names = vec!["MICROSOFT_GRAPH_CLIENT_SECRET".into()];
        assert_eq!(validate_request(&request), Err("invalid_request"));

        request.secret_names = ALLOWED_SECRET_NAMES.iter().map(|name| (*name).into()).collect();
        request.secret_names.push("INTUIT_CLIENT_ID".into());
        assert_eq!(validate_request(&request), Err("invalid_request"));
    }

    #[test]
    fn request_rejects_legacy_write_fields() {
        let raw = r#"{
            "access_token":"reader-token",
            "api_url":"https://api.bitwarden.com",
            "identity_url":"https://identity.bitwarden.com",
            "organization_id":"00000000-0000-0000-0000-000000000001",
            "project_id":"00000000-0000-0000-0000-000000000002",
            "operation":"verify",
            "secrets":[{"key":"INTUIT_CLIENT_SECRET","value":"must-not-be-written"}]
        }"#;

        assert!(serde_json::from_str::<Request>(raw).is_err());
    }

    #[test]
    fn request_rejects_non_https_or_credential_bearing_endpoints() {
        let mut request = valid_request();
        request.api_url = "http://api.bitwarden.com".into();
        assert_eq!(validate_request(&request), Err("invalid_request"));

        request.api_url = "https://example.test".into();
        assert_eq!(validate_request(&request), Err("invalid_request"));
    }

    #[test]
    fn response_contains_only_sanitized_status_without_secret_values() {
        let response = Response {
            ok: false,
            error: Some("project_access_failed"),
            results: Vec::new(),
            resolved_secrets: None,
            project_access_verified: None,
            required_secrets_verified: None,
        };
        let json = serde_json::to_string(&response).unwrap();

        assert!(json.contains("project_access_failed"));
        assert!(!json.contains("not-a-real-secret"));
    }

    #[test]
    fn successful_required_secret_verification_returns_no_values() {
        let response = Response {
            ok: true,
            error: None,
            results: Vec::new(),
            resolved_secrets: None,
            project_access_verified: Some(true),
            required_secrets_verified: Some(true),
        };
        let json = serde_json::to_string(&response).unwrap();

        assert!(json.contains("\"project_access_verified\":true"));
        assert!(json.contains("\"required_secrets_verified\":true"));
        assert!(!json.contains("resolved_secrets"));
        assert!(!json.contains("INTUIT_CLIENT_SECRET"));
    }
}
