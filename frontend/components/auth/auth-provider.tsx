"use client";

import * as React from "react";

import { ApiError } from "@/lib/api-client";
import { clearToken, getToken, setToken } from "@/lib/auth-storage";
import { authService, userService } from "@/services";
import type { LoginInput, RegisterInput, User } from "@/types/api";

type AuthStatus = "loading" | "authenticated" | "unauthenticated";

interface AuthContextValue {
  user: User | null;
  status: AuthStatus;
  isAuthenticated: boolean;
  login: (input: Omit<LoginInput, "device_name">) => Promise<User>;
  register: (input: Omit<RegisterInput, "device_name">) => Promise<User>;
  logout: () => Promise<void>;
  /** Re-fetch the user from the API (after onboarding, goal changes, …). */
  refresh: () => Promise<User | null>;
  /** Replace the cached user with a fresher copy returned by a mutation. */
  setUser: (user: User) => void;
}

const AuthContext = React.createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUserState] = React.useState<User | null>(null);
  const [status, setStatus] = React.useState<AuthStatus>("loading");

  // Bootstrap: if a token is present, exchange it for the current user.
  React.useEffect(() => {
    let cancelled = false;

    async function bootstrap() {
      if (!getToken()) {
        setStatus("unauthenticated");
        return;
      }

      try {
        const { data } = await userService.me();
        if (cancelled) return;
        setUserState(data);
        setStatus("authenticated");
      } catch (error) {
        if (cancelled) return;
        // apiFetch already dropped the token on a 401.
        if (!(error instanceof ApiError) || error.status !== 401) {
          clearToken();
        }
        setUserState(null);
        setStatus("unauthenticated");
      }
    }

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, []);

  const login = React.useCallback<AuthContextValue["login"]>(async (input) => {
    const { data } = await authService.login(input);
    setToken(data.token);
    setUserState(data.user);
    setStatus("authenticated");
    return data.user;
  }, []);

  const register = React.useCallback<AuthContextValue["register"]>(async (input) => {
    const { data } = await authService.register(input);
    setToken(data.token);
    setUserState(data.user);
    setStatus("authenticated");
    return data.user;
  }, []);

  const logout = React.useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      // Even if revoking server-side fails, sign out locally.
    } finally {
      clearToken();
      setUserState(null);
      setStatus("unauthenticated");
    }
  }, []);

  const refresh = React.useCallback(async () => {
    if (!getToken()) {
      setUserState(null);
      setStatus("unauthenticated");
      return null;
    }

    try {
      const { data } = await userService.me();
      setUserState(data);
      setStatus("authenticated");
      return data;
    } catch {
      setUserState(null);
      setStatus("unauthenticated");
      return null;
    }
  }, []);

  const value = React.useMemo<AuthContextValue>(
    () => ({
      user,
      status,
      isAuthenticated: status === "authenticated" && user !== null,
      login,
      register,
      logout,
      refresh,
      setUser: setUserState,
    }),
    [user, status, login, register, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuthContext(): AuthContextValue {
  const context = React.useContext(AuthContext);

  if (!context) {
    throw new Error("useAuth must be used inside <AuthProvider>.");
  }

  return context;
}