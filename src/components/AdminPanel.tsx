
import { useState } from "react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import SettingsForm from "./SettingsForm";
import ScraperDebug from "./ScraperDebug";
import { 
  Settings, 
  Database, 
  History,
  Home,
  Bug
} from "lucide-react";
import { Link } from "react-router-dom";

const AdminPanel = () => {
  const [activeTab, setActiveTab] = useState("settings");

  return (
    <div className="w-full">
      <div className="border-b border-border/40 mb-8">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h1 className="text-3xl font-serif font-semibold">Obituary Scraper Settings</h1>
              <p className="text-muted-foreground mt-1">
                Configure the Ontario obituary scraper for Monaco Monuments
              </p>
            </div>
            
            <Link to="/">
              <Button variant="outline" size="sm" className="text-sm">
                <Home className="h-4 w-4 mr-2" />
                Back to Obituaries
              </Button>
            </Link>
          </div>
        </div>
      </div>
      
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-16">
        <Tabs
          value={activeTab}
          onValueChange={setActiveTab}
          className="w-full"
        >
          <div className="flex justify-center mb-8">
            <TabsList className="bg-secondary/50 p-1">
              <TabsTrigger
                value="settings"
                className={cn(
                  "data-[state=active]:bg-background data-[state=active]:shadow-sm",
                  "transition-all duration-200"
                )}
              >
                <Settings className="h-4 w-4 mr-2" />
                Settings
              </TabsTrigger>
              <TabsTrigger 
                value="debug"
                className={cn(
                  "data-[state=active]:bg-background data-[state=active]:shadow-sm",
                  "transition-all duration-200"
                )}
              >
                <Bug className="h-4 w-4 mr-2" />
                Debug
              </TabsTrigger>
              <TabsTrigger 
                value="data"
                className={cn(
                  "data-[state=active]:bg-background data-[state=active]:shadow-sm",
                  "transition-all duration-200"
                )}
              >
                <Database className="h-4 w-4 mr-2" />
                Data Management
              </TabsTrigger>
              <TabsTrigger 
                value="logs"
                className={cn(
                  "data-[state=active]:bg-background data-[state=active]:shadow-sm",
                  "transition-all duration-200"
                )}
              >
                <History className="h-4 w-4 mr-2" />
                Activity Logs
              </TabsTrigger>
            </TabsList>
          </div>
          
          <TabsContent value="settings" className="animate-fade-in">
            <SettingsForm />
          </TabsContent>
          
          <TabsContent value="debug" className="animate-fade-in">
            <ScraperDebug />
          </TabsContent>
          
          <TabsContent value="data" className="animate-fade-in">
            <div className="bg-muted/20 border border-border/30 rounded-lg p-12 text-center">
              <h3 className="text-lg font-medium">Data Management</h3>
              <p className="text-muted-foreground mt-2">
                This section is under development and will be available soon.
              </p>
            </div>
          </TabsContent>
          
          <TabsContent value="logs" className="animate-fade-in">
            <div className="bg-muted/20 border border-border/30 rounded-lg p-12 text-center">
              <h3 className="text-lg font-medium">Activity Logs</h3>
              <p className="text-muted-foreground mt-2">
                This section is under development and will be available soon.
              </p>
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default AdminPanel;
