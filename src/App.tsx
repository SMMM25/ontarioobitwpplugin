
import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { memo } from "react";
import Index from "./pages/Index";
import Settings from "./pages/Settings";
import NotFound from "./pages/NotFound";
import ObituaryShare from "./pages/ObituaryShare";

// Create QueryClient outside component to prevent recreation on rerenders
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false, // Disable automatic refetching when window is focused
      retry: 1, // Only retry failed queries once
    },
  },
});

// Memoize route components to prevent unnecessary rerenders
const MemoizedIndex = memo(Index);
const MemoizedSettings = memo(Settings);
const MemoizedNotFound = memo(NotFound);
const MemoizedObituaryShare = memo(ObituaryShare);

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<MemoizedIndex />} />
          <Route path="/settings" element={<MemoizedSettings />} />
          <Route path="/obituary/:id" element={<MemoizedObituaryShare />} />
          {/* ADD ALL CUSTOM ROUTES ABOVE THE CATCH-ALL "*" ROUTE */}
          <Route path="*" element={<MemoizedNotFound />} />
        </Routes>
      </BrowserRouter>
      {/* Place toasters outside of route structure to prevent remounting */}
      <Toaster />
      <Sonner />
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
